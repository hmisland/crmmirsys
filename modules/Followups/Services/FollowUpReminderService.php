<?php

declare(strict_types=1);

namespace Modules\Followups\Services;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Mail\MailReadiness;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Followups\FollowUpReminderRepository;
use Modules\Followups\FollowUpRepository;

/**
 * Delivers due follow-up reminders (follow_up_reminders, created by the
 * automation rules with reminder_minutes_before) — run by the shared
 * scheduler (app/bin/scheduler.php, task "followups.reminders").
 *
 * A due reminder becomes ONE email to the follow-up's assignee (or its
 * owner when unassigned), in their language, through the CRM's mailer.
 * Skipped when the follow-up is gone or closed (completed / cancelled /
 * skipped), or the person is inactive, of another organization, or no
 * longer has the Follow-ups module. While mail is not configured nothing
 * is claimed, so reminders wait and go out once it is. Each reminder is
 * claimed atomically (FollowUpReminderRepository::claim()), so
 * overlapping runs never send it twice.
 */
final class FollowUpReminderService
{
    private const CLOSED = ['completed', 'cancelled', 'skipped'];

    public function __construct(
        private readonly FollowUpReminderRepository $reminders,
        private readonly FollowUpRepository $followUps,
        private readonly UserRepository $users,
        private readonly ModuleAccessResolver $modules,
        private readonly OrganizationTimezone $clock,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?\Closure $mailReady = null,
        private readonly string $appUrl = '',
        private readonly string $langPath = '',
    ) {
    }

    /** @return array{sent: int, skipped: int, failed: int, waiting: int} */
    public function processDue(int $budgetSeconds = 50, int $limit = 500): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0];
        $due = array_slice($this->reminders->duePending($this->clock), 0, max(1, $limit));
        $ready = $this->mailer !== null && ($this->mailReady !== null ? (bool) ($this->mailReady)() : MailReadiness::isConfigured());
        if (!$ready) {
            $result['waiting'] = count($due);

            return $result;
        }

        $deadline = time() + max(1, $budgetSeconds);
        foreach ($due as $row) {
            if (time() >= $deadline) {
                break;
            }
            if (!$this->reminders->claim((int) $row['id'])) {
                continue; // another run has it
            }
            $outcome = OrganizationContext::runAs((int) $row['organization_id'], fn (): string => $this->deliver($row));
            if ($outcome !== 'sent') {
                $this->reminders->finish((int) $row['id'], $outcome);
            }
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return string sent | skipped | failed */
    private function deliver(array $row): string
    {
        $organizationId = (int) $row['organization_id'];
        $followUp = $this->followUps->findById((int) $row['follow_up_id']);
        if ($followUp === null || in_array((string) $followUp['status'], self::CLOSED, true)) {
            return 'skipped';
        }

        $user = $this->users->findById((int) ($followUp['assignee_user_id'] ?: $followUp['owner_id']));
        if ($user === null
            || (int) ($user['organization_id'] ?? 0) !== $organizationId
            || ($user['status'] ?? '') !== 'active'
            || !$this->modules->resolvedForUser('followups', $user)) {
            return 'skipped';
        }
        $address = trim((string) ($user['email'] ?? ''));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return 'failed';
        }

        [$subject, $body] = $this->compose($user, $followUp);
        try {
            $this->mailer->send($address, $subject, $body);
        } catch (\Throwable) {
            return 'failed';
        }

        return 'sent';
    }

    /**
     * Plain text in the recipient's language: title, due date/time (the
     * organization's wall clock), and the link to the follow-up.
     *
     * @return array{0: string, 1: string}
     */
    private function compose(array $user, array $followUp): array
    {
        $locale = in_array($user['language'] ?? null, Translator::SUPPORTED_LOCALES, true) ? (string) $user['language'] : 'en';
        $tr = new Translator($locale, $this->langPath !== '' ? $this->langPath : dirname(__DIR__, 3) . '/lang');
        $fmt = new LocaleFormatter($locale);

        $lines = [
            $tr->get('followups.email.greeting', ['name' => trim((string) ($user['first_name'] ?? ''))]),
            '',
            $tr->get('followups.email.intro'),
            '',
            $tr->get('followups.email.title') . ': ' . $followUp['title'],
        ];
        if (!empty($followUp['due_at'])) {
            $lines[] = $tr->get('followups.email.due') . ': ' . $fmt->date((string) $followUp['due_at']) . ' ' . $fmt->digits(substr((string) $followUp['due_at'], 11, 5));
        }
        $base = rtrim($this->appUrl !== '' ? $this->appUrl : (string) Config::get('app.url', ''), '/');
        $lines[] = '';
        $lines[] = $tr->get('followups.email.open') . ': ' . $base . '/followups/' . (int) $followUp['id'];

        return [$tr->get('followups.email.subject', ['title' => $followUp['title']]), implode("\n", $lines)];
    }
}
