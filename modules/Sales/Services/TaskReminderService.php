<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Core\Exceptions\NotFoundException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Sales\TaskNotificationRepository;
use Modules\Sales\TaskRepository;

/**
 * Task reminders (T6): turning due reminders into in-app notifications
 * (the cron processor, modules/Sales/bin/task-reminders.php) and a user's
 * reminder inbox.
 *
 * A reminder (tasks.remind_at) is the organization's local wall clock, so
 * it is compared with that organization's own "now"
 * (OrganizationTimezone::localNow()) — never the server's or browser's.
 * Each occurrence (tasks.remind_seq) is claimed atomically and fires once;
 * a re-run, an overlapping run or a retry creates nothing new.
 *
 * Recipients: the task's assignees (the creator when it has none) who are
 * still active members of the task's organization, have the Sales module
 * and may see the task by the same rule as everywhere else
 * (TaskService::canView()). Archived, closed and deleted tasks notify
 * nobody — their occurrence is just marked handled.
 *
 * The inbox shows a notification only while its task exists and the user
 * may still see it, so nothing about a task the user lost access to (or
 * that was deleted) shows through it.
 *
 * Email (T7, opt-in per task: tasks.remind_email). The occurrence's
 * notification row carries its email state, so there is one email per
 * recipient and occurrence. Delivery goes through the CRM's own mailer
 * (MailerInterface, MAIL_DRIVER) and is claimed atomically per row; an
 * email is marked sent only after the mailer accepted it. A failure is
 * retried with back-off (MAX_EMAIL_ATTEMPTS); without a configured mailer
 * nothing is attempted and the email waits (and expires after
 * EMAIL_EXPIRY_HOURS) — never marked sent. Eligibility is checked again
 * just before sending; the email shows the related record only when the
 * recipient may see it.
 */
final class TaskReminderService
{
    private const CLOSED = ['completed', 'rejected', 'cancelled'];
    private const INBOX_LIMIT = 100;
    public const MAX_EMAIL_ATTEMPTS = 5;
    private const EMAIL_RETRY_MINUTES = 5; // doubled after each failure
    private const EMAIL_WAIT_MINUTES = 15; // mailer not configured: look again then
    private const EMAIL_EXPIRY_HOURS = 48;
    private const EMAIL_CLAIM_MINUTES = 10; // an older claim belongs to a run that died

    /**
     * @param (\Closure(): bool)|null $mailReady whether a real mail transport is configured (default: mailConfigured())
     */
    public function __construct(
        private readonly TaskNotificationRepository $notifications,
        private readonly TaskRepository $tasks,
        private readonly TaskService $taskService,
        private readonly UserRepository $users,
        private readonly ModuleAccessResolver $modules,
        private readonly OrganizationTimezone $clock,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?\Closure $mailReady = null,
        private readonly string $appUrl = '',
        private readonly string $langPath = '',
    ) {
    }

    /**
     * Whether the CRM's mailer can really send: SMTP with host, user,
     * password and a sender; PHP mail() with a sender; the "log" driver
     * (files in storage/mail) only outside production.
     */
    public static function mailConfigured(): bool
    {
        $from = (string) Config::get('mail.from_address', '');
        $sender = filter_var($from, FILTER_VALIDATE_EMAIL) !== false && !str_ends_with(strtolower($from), '@example.com');

        return match ((string) Config::get('mail.driver', 'log')) {
            'smtp' => $sender && (string) Config::get('mail.smtp.host', '') !== '' && (string) Config::get('mail.smtp.username', '') !== ''
                && (string) Config::get('mail.smtp.password', '') !== '',
            'php_mail' => $sender,
            'log' => Config::get('app.env', 'production') !== 'production',
            default => false,
        };
    }

    // --- Processor -------------------------------------------------------------

    /**
     * Every organization with pending reminders, each in its own context and
     * clock. One organization's failure does not stop the others.
     *
     * @return array{organizations: int, reminders: int, notifications: int, emails_sent: int, emails_failed: int, errors: string[]}
     */
    public function runAll(int $perOrganization = 500): array
    {
        $stats = ['organizations' => 0, 'reminders' => 0, 'notifications' => 0, 'emails_sent' => 0, 'emails_failed' => 0, 'errors' => []];
        $organizations = array_values(array_unique(array_merge(
            $this->notifications->organizationsWithPendingReminders(),
            $this->notifications->organizationsWithPendingEmails()
        )));
        sort($organizations);

        foreach ($organizations as $organizationId) {
            $stats['organizations']++;
            try {
                $result = OrganizationContext::runAs($organizationId, fn () => $this->processCurrentOrganization($perOrganization));
                $stats['reminders'] += $result['reminders'];
                $stats['notifications'] += $result['notifications'];
                $emails = OrganizationContext::runAs($organizationId, fn () => $this->deliverEmails($perOrganization));
                $stats['emails_sent'] += $emails['sent'];
                $stats['emails_failed'] += $emails['failed'];
            } catch (\Throwable $e) {
                $stats['errors'][] = 'organization ' . $organizationId . ': ' . $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * The current organization's due reminders.
     *
     * @return array{reminders: int, notifications: int}
     */
    public function processCurrentOrganization(int $limit = 500): array
    {
        $organizationId = OrganizationContext::id();
        $now = $this->clock->localNow($organizationId);
        $result = ['reminders' => 0, 'notifications' => 0];

        foreach ($this->notifications->dueReminders($now, $limit) as $task) {
            // Claim and notify together: either the occurrence is handled and
            // its notifications exist, or neither (a failure leaves it due).
            $created = $this->notifications->atomically(function () use ($task, $organizationId): ?int {
                if (!$this->notifications->claim((int) $task['id'], (int) $task['remind_seq'])) {
                    return null; // handled by another run, or changed meanwhile
                }
                if (!$this->notifies($task)) {
                    return 0;
                }
                $count = 0;
                foreach ($this->recipients($task, $organizationId) as $userId) {
                    $count += $this->notifications->addReminder($task, $userId) ? 1 : 0;
                }

                return $count;
            });

            if ($created !== null) {
                $result['reminders']++;
                $result['notifications'] += $created;
            }
        }

        return $result;
    }

    private function notifies(array $task): bool
    {
        return ($task['deleted_at'] ?? null) === null
            && ($task['archived_at'] ?? null) === null
            && !in_array($task['status'] ?? '', self::CLOSED, true);
    }

    /** @return int[] users to notify for this task */
    private function recipients(array $task, int $organizationId): array
    {
        $ids = $this->tasks->assigneeIds((int) $task['id']);
        if (!empty($task['assigned_to_user_id'])) {
            $ids[] = (int) $task['assigned_to_user_id'];
        }
        if ($ids === [] && !empty($task['created_by_user_id'])) {
            $ids[] = (int) $task['created_by_user_id'];
        }

        $recipients = [];
        foreach (array_unique(array_map('intval', $ids)) as $userId) {
            $user = $this->users->findById($userId);
            if ($user === null
                || (int) ($user['organization_id'] ?? 0) !== $organizationId
                || ($user['status'] ?? '') !== 'active'
                || !$this->modules->resolvedForUser('sales', $user)
                || !$this->taskService->canView($user, $task)) {
                continue;
            }
            $recipients[] = $userId;
        }

        return $recipients;
    }

    // --- Email -----------------------------------------------------------------

    /**
     * Sends the current organization's due reminder emails.
     *
     * @return array{sent: int, failed: int, retry: int, skipped: int, waiting: int}
     */
    public function deliverEmails(int $limit = 100): array
    {
        $organizationId = OrganizationContext::id();
        $utc = new \DateTimeZone('UTC');
        $now = $this->clock->now($organizationId)->setTimezone($utc);
        $at = fn (string $modify): string => $now->modify($modify)->format('Y-m-d H:i:s');
        $nowText = $now->format('Y-m-d H:i:s');
        $stale = $at('-' . self::EMAIL_CLAIM_MINUTES . ' minutes');
        $ready = $this->mailer !== null && ($this->mailReady !== null ? (bool) ($this->mailReady)() : self::mailConfigured());
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'waiting' => 0];

        foreach ($this->notifications->pendingEmails($nowText, $stale, $limit) as $row) {
            $token = bin2hex(random_bytes(16));
            if (!$this->notifications->claimEmail((int) $row['id'], $token, $nowText, $stale)) {
                continue; // another run has it
            }
            $finish = fn (array $fields) => $this->notifications->finishEmail((int) $row['id'], $token, $fields);

            if (!$ready) {
                if ($row['created_at'] < $at('-' . self::EMAIL_EXPIRY_HOURS . ' hours')) {
                    $finish(['email_status' => 'failed', 'email_error' => 'expired: mail not configured']);
                    $result['failed']++;
                } else {
                    $finish(['email_error' => 'mail not configured', 'email_next_at' => $at('+' . self::EMAIL_WAIT_MINUTES . ' minutes')]);
                    $result['waiting']++;
                }
                continue;
            }

            [$user, $task] = $this->emailTarget($row, $organizationId);
            if ($user === null || $task === null) {
                $finish(['email_status' => 'skipped', 'email_error' => 'no longer eligible']);
                $result['skipped']++;
                continue;
            }
            $address = trim((string) ($user['email'] ?? ''));
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $finish(['email_status' => 'failed', 'email_error' => 'no valid email address']);
                $result['failed']++;
                continue;
            }

            [$subject, $body] = $this->composeEmail($user, $task, $row, $organizationId);
            try {
                $this->mailer->send($address, $subject, $body);
            } catch (\Throwable $e) {
                $attempts = (int) $row['email_attempts'] + 1;
                $final = $attempts >= self::MAX_EMAIL_ATTEMPTS;
                $finish([
                    'email_status' => $final ? 'failed' : 'pending',
                    'email_attempts' => $attempts,
                    'email_error' => mb_substr($e->getMessage(), 0, 255),
                    'email_next_at' => $final ? null : $at('+' . (self::EMAIL_RETRY_MINUTES * 2 ** ($attempts - 1)) . ' minutes'),
                ]);
                $result[$final ? 'failed' : 'retry']++;
                continue;
            }
            $finish(['email_status' => 'sent', 'email_attempts' => (int) $row['email_attempts'] + 1, 'email_sent_at' => $nowText, 'email_error' => null, 'email_next_at' => null]);
            $result['sent']++;
        }

        return $result;
    }

    /** @return array{0: ?array, 1: ?array} the recipient and the task — both null unless still eligible right now */
    private function emailTarget(array $row, int $organizationId): array
    {
        $task = $this->tasks->findByIds([(int) $row['task_id']])[(int) $row['task_id']] ?? null;
        if ($task === null || !$this->notifies($task)) {
            return [null, null];
        }
        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null
            || (int) ($user['organization_id'] ?? 0) !== $organizationId
            || ($user['status'] ?? '') !== 'active'
            || !$this->modules->resolvedForUser('sales', $user)
            || !$this->taskService->canView($user, $task)) {
            return [null, null];
        }

        return [$user, $task];
    }

    /**
     * Plain text in the recipient's language: title, the reminder's local
     * date/time with the organization's time zone, the due date, the related
     * record only when the recipient may see it, and the task link.
     *
     * @return array{0: string, 1: string}
     */
    private function composeEmail(array $user, array $task, array $row, int $organizationId): array
    {
        $locale = in_array($user['language'] ?? null, Translator::SUPPORTED_LOCALES, true) ? (string) $user['language'] : 'en';
        $tr = new Translator($locale, $this->langPath !== '' ? $this->langPath : dirname(__DIR__, 3) . '/lang');
        $fmt = new LocaleFormatter($locale);
        $point = fn (string $value, mixed $allDay): string => $fmt->date($value)
            . (!((bool) $allDay) || substr($value, 11, 5) !== '00:00' ? ' ' . $fmt->digits(substr($value, 11, 5)) : '');

        $lines = [
            $tr->get('tasks.email.greeting', ['name' => trim((string) ($user['first_name'] ?? ''))]),
            '',
            $tr->get('tasks.email.intro'),
            '',
            $tr->get('tasks.field_title') . ': ' . $task['title'],
            $tr->get('tasks.reminder') . ': ' . $point((string) $row['remind_at'], $row['remind_all_day'] ?? 1)
                . ' (' . $this->clock->forOrganization($organizationId)->getName() . ')',
        ];
        if (!empty($task['due_at'])) {
            $lines[] = $tr->get('tasks.due') . ': ' . $point((string) $task['due_at'], $task['due_all_day'] ?? 1);
        }
        $related = $this->taskService->relatedFor($user, [$task])[(int) $task['id']] ?? null;
        if ($related !== null && $related['state'] === 'ok') {
            $lines[] = $tr->get('tasks.related.' . $related['type']) . ': ' . $related['label'];
        }
        $base = rtrim($this->appUrl !== '' ? $this->appUrl : (string) Config::get('app.url', ''), '/');
        $lines[] = '';
        $lines[] = $tr->get('tasks.email.open') . ': ' . $base . '/tasks/' . (int) $task['id'];

        return [$tr->get('tasks.email.subject', ['title' => $task['title']]), implode("\n", $lines)];
    }

    // --- Inbox -----------------------------------------------------------------

    /**
     * The user's reminder notifications whose task still exists and is
     * visible to them, newest first, each with its task.
     *
     * @return array<int, array{notification: array, task: array}>
     */
    public function inbox(array $user, bool $unreadOnly = false): array
    {
        $rows = $this->notifications->forUser((int) $user['id'], $unreadOnly, self::INBOX_LIMIT);
        $tasks = $this->tasks->findByIds(array_map(fn (array $n) => (int) $n['task_id'], $rows));

        $items = [];
        $visible = [];
        foreach ($rows as $row) {
            $task = $tasks[(int) $row['task_id']] ?? null;
            if ($task === null) {
                continue; // deleted
            }
            $visible[(int) $task['id']] ??= $this->taskService->canView($user, $task);
            if ($visible[(int) $task['id']]) {
                $items[] = ['notification' => $row, 'task' => $task];
            }
        }

        return $items;
    }

    public function unreadCount(array $user): int
    {
        return count($this->inbox($user, true));
    }

    /** Only the user's own notification of this organization; anything else is a 404. */
    public function markRead(array $user, int $notificationId): void
    {
        if ($this->notifications->findForUser($notificationId, (int) $user['id']) === null) {
            throw new NotFoundException('Notification not found.');
        }
        $this->notifications->markRead((int) $user['id'], $notificationId);
    }

    public function markAllRead(array $user): int
    {
        return $this->notifications->markRead((int) $user['id']);
    }

    /** Opening a task reads its reminders for this user. */
    public function markTaskRead(array $user, int $taskId): void
    {
        $this->notifications->markRead((int) $user['id'], null, $taskId);
    }
}
