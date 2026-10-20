<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Repositories\AdminAnnouncementRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\PlatformTicketRepository;
use App\Infrastructure\Repositories\UserRepository;

/**
 * Platform tickets: organization admins (organization.edit) write to the
 * platform team; the Super Admin answers and manages them.
 *
 * Organization side: only its own organization's tickets, ever. A reply
 * from the organization makes the ticket "open" again (waiting for the
 * platform); a reply from the platform makes it "answered". Either side
 * can close; replying to a closed ticket reopens it. Messages are never
 * edited or deleted.
 *
 * The ticket's creator learns about a platform reply or close through the
 * existing announcement bell (in their language); the organization's
 * timeline keeps the audit trail. The Super Admin sees the number of
 * waiting tickets in the menu.
 *
 * Attachments (any message, either side): txt, csv, jpg, png, webp, at
 * most 5 MB each and 5 per message; the content must match the
 * extension. Stored through the shared AttachmentService (random name,
 * outside the web root) on the message, inside the ticket's organization;
 * downloaded only through the ticket's own page checks.
 */
final class PlatformTicketService
{
    public const CATEGORIES = ['general', 'technical', 'account', 'modules', 'feature', 'other'];
    public const PRIORITIES = ['normal', 'high', 'urgent'];
    public const SUBJECT_MAX = 200;
    public const BODY_MAX = 5000;
    /** New tickets one user may open per hour. */
    public const HOURLY_LIMIT = 10;
    public const ATTACHMENT_SUBJECT = 'platform_ticket_message';
    public const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;
    public const ATTACHMENTS_PER_MESSAGE = 5;
    /** Allowed extension => MIME types the file's real content may have. */
    public const ATTACHMENT_TYPES = [
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    public function __construct(
        private readonly PlatformTicketRepository $tickets,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly AdminAnnouncementRepository $announcements,
        private readonly UserRepository $users,
        private readonly string $langPath,
        private readonly ?AttachmentService $attachments = null,
    ) {
    }

    // --- Organization side ------------------------------------------------------

    public function canUse(array $user): bool
    {
        return (int) ($user['organization_id'] ?? 0) > 0 && $this->authorization->can($user, Permission::ORGANIZATION_EDIT);
    }

    public function organizationPage(array $user, string $status, int $limit, int $offset): array
    {
        return $this->tickets->organizationPage($this->organizationAdmin($user), $status, $limit, $offset);
    }

    public function organizationCount(array $user, string $status): int
    {
        return $this->tickets->count(['organization_id' => $this->organizationAdmin($user), 'status' => $status]);
    }

    /**
     * @param list<array> $files uploaded files (Request::files())
     * @param int|null $failedFiles set to the number of files that could not be saved (the ticket is saved anyway)
     * @return int the new ticket id
     */
    public function create(array $user, array $input, array $files = [], ?int &$failedFiles = null): int
    {
        $organizationId = $this->organizationAdmin($user);
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $category = (string) ($input['category'] ?? '');
        $priority = (string) ($input['priority'] ?? 'normal');
        $errors = [];
        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX) {
            $errors['subject'] = ['platform_tickets.error.subject'];
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors['category'] = ['platform_tickets.error.category'];
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            $errors['priority'] = ['platform_tickets.error.priority'];
        }
        if ($body === '' || mb_strlen($body) > self::BODY_MAX) {
            $errors['body'] = ['platform_tickets.error.body'];
        }
        try {
            $files = $this->checkedFiles($files);
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->tickets->createdByUserSince((int) $user['id'], date('Y-m-d H:i:s', time() - 3600)) >= self::HOURLY_LIMIT) {
            throw new ValidationException(['ticket' => ['platform_tickets.error.rate_limited']]);
        }

        [$id, $messageId] = $this->tickets->atomically(fn (): array => $this->tickets->create($organizationId, (int) $user['id'], $subject, $category, $priority, $body));
        $this->audit($organizationId, 'platform_ticket.created', 'Ticket #' . $id . ' sent to the platform team', (int) $user['id']);
        $failedFiles = $this->storeFiles($organizationId, $messageId, (int) $user['id'], $files);

        return $id;
    }

    /** @return array{ticket: array, messages: list<array>} */
    public function showForOrganization(array $user, int $id): array
    {
        $ticket = $this->tickets->findForOrganization($id, $this->organizationAdmin($user));
        if ($ticket === null) {
            throw new NotFoundException();
        }

        return $this->thread($ticket);
    }

    /**
     * @param list<array> $files uploaded files (Request::files())
     * @return int files that could not be saved (the reply is saved anyway)
     */
    public function replyAsOrganization(array $user, int $id, string $body, array $files = []): int
    {
        $organizationId = $this->organizationAdmin($user);
        $body = $this->body($body);
        $files = $this->checkedFiles($files);
        $messageId = $this->tickets->atomically(function () use ($user, $id, $organizationId, $body): int {
            $ticket = $this->tickets->lock($id);
            if ($ticket === null || (int) $ticket['organization_id'] !== $organizationId) {
                throw new NotFoundException();
            }
            $messageId = $this->tickets->addMessage($id, (int) $user['id'], PlatformTicketRepository::SIDE_ORGANIZATION, $body);
            $this->tickets->setStatus($id, PlatformTicketRepository::OPEN, null);

            return $messageId;
        });

        return $this->storeFiles($organizationId, $messageId, (int) $user['id'], $files);
    }

    public function closeAsOrganization(array $user, int $id): void
    {
        $organizationId = $this->organizationAdmin($user);
        $this->tickets->atomically(function () use ($user, $id, $organizationId): void {
            $ticket = $this->tickets->lock($id);
            if ($ticket === null || (int) $ticket['organization_id'] !== $organizationId) {
                throw new NotFoundException();
            }
            if ($ticket['status'] === PlatformTicketRepository::CLOSED) {
                throw new ValidationException(['ticket' => ['platform_tickets.error.already_closed']]);
            }
            $this->tickets->setStatus($id, PlatformTicketRepository::CLOSED, (int) $user['id']);
        });
        $this->audit($organizationId, 'platform_ticket.closed', 'Ticket #' . $id . ' closed by the organization', (int) $user['id']);
    }

    /** Tickets answered by the platform and waiting for this organization (menu count). */
    public function answeredCount(array $user): int
    {
        return $this->canUse($user) ? $this->tickets->answeredCountForOrganization((int) $user['organization_id']) : 0;
    }

    // --- Super Admin side --------------------------------------------------------------

    public function adminPage(array $user, array $filters, int $limit, int $offset): array
    {
        $this->superAdmin($user);

        return $this->tickets->adminPage($filters, $limit, $offset);
    }

    public function adminCount(array $user, array $filters): int
    {
        $this->superAdmin($user);

        return $this->tickets->count($filters);
    }

    /** @return array{ticket: array, messages: list<array>} */
    public function showForAdmin(array $user, int $id): array
    {
        $this->superAdmin($user);
        $ticket = $this->tickets->find($id);
        if ($ticket === null) {
            throw new NotFoundException();
        }

        return $this->thread($ticket);
    }

    /**
     * Platform reply; "close" also closes the ticket in the same step.
     *
     * @param list<array> $files uploaded files (Request::files())
     * @return int files that could not be saved (the reply is saved anyway)
     */
    public function replyAsPlatform(array $user, int $id, string $body, bool $close, array $files = []): int
    {
        $this->superAdmin($user);
        $body = $this->body($body);
        $files = $this->checkedFiles($files);
        [$ticket, $messageId] = $this->tickets->atomically(function () use ($user, $id, $body, $close): array {
            $ticket = $this->tickets->lock($id);
            if ($ticket === null) {
                throw new NotFoundException();
            }
            $messageId = $this->tickets->addMessage($id, (int) $user['id'], PlatformTicketRepository::SIDE_PLATFORM, $body);
            $this->tickets->setStatus($id, $close ? PlatformTicketRepository::CLOSED : PlatformTicketRepository::ANSWERED, $close ? (int) $user['id'] : null);

            return [$ticket, $messageId];
        });
        $failed = $this->storeFiles((int) $ticket['organization_id'], $messageId, (int) $user['id'], $files);
        $this->audit((int) $ticket['organization_id'], 'platform_ticket.answered', 'Ticket #' . $id . ' answered by the platform team', (int) $user['id']);
        $this->notify($ticket, $user, $close ? 'answered_closed' : 'answered');

        return $failed;
    }

    public function setStatusAsPlatform(array $user, int $id, string $status): void
    {
        $this->superAdmin($user);
        if (!in_array($status, [PlatformTicketRepository::CLOSED, PlatformTicketRepository::OPEN], true)) {
            throw new ValidationException(['status' => ['platform_tickets.error.status']]);
        }
        $ticket = $this->tickets->atomically(function () use ($user, $id, $status): array {
            $ticket = $this->tickets->lock($id);
            if ($ticket === null) {
                throw new NotFoundException();
            }
            if (($status === PlatformTicketRepository::CLOSED) === ($ticket['status'] === PlatformTicketRepository::CLOSED)) {
                throw new ValidationException(['status' => ['platform_tickets.error.' . ($status === PlatformTicketRepository::CLOSED ? 'already_closed' : 'not_closed')]]);
            }
            $this->tickets->setStatus($id, $status, (int) $user['id']);

            return $ticket;
        });
        $this->audit((int) $ticket['organization_id'], 'platform_ticket.' . ($status === PlatformTicketRepository::CLOSED ? 'closed' : 'reopened'), 'Ticket #' . $id . ' ' . ($status === PlatformTicketRepository::CLOSED ? 'closed' : 'reopened') . ' by the platform team', (int) $user['id']);
        if ($status === PlatformTicketRepository::CLOSED) {
            $this->notify($ticket, $user, 'closed');
        }
    }

    public function setPriority(array $user, int $id, string $priority): void
    {
        $this->superAdmin($user);
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new ValidationException(['priority' => ['platform_tickets.error.priority']]);
        }
        if ($this->tickets->find($id) === null) {
            throw new NotFoundException();
        }
        $this->tickets->setPriority($id, $priority);
    }

    public function openCount(): int
    {
        return $this->tickets->openCount();
    }

    /**
     * One attachment of one ticket, for download: the organization side only
     * for its own tickets, the platform side for any. Null when the file is
     * not one of THIS ticket's messages' attachments.
     *
     * @return array{attachment: array, path: string}|null
     */
    public function attachment(array $user, int $ticketId, int $attachmentId): ?array
    {
        $ticket = !empty($user['is_super_admin']) && (int) ($user['organization_id'] ?? 0) === 0
            ? $this->tickets->find($ticketId)
            : $this->tickets->findForOrganization($ticketId, $this->organizationAdmin($user));
        if ($ticket === null || $this->attachments === null) {
            return null;
        }
        $messageIds = array_map('intval', array_column($this->tickets->messages($ticketId), 'id'));

        return OrganizationContext::runAs((int) $ticket['organization_id'], function () use ($attachmentId, $messageIds): ?array {
            foreach ($messageIds as $messageId) {
                $match = $this->attachments->findForSubject(self::ATTACHMENT_SUBJECT, $messageId, $attachmentId);
                if ($match !== null) {
                    $path = $this->attachments->absolutePath($match);

                    return is_file($path) ? ['attachment' => $match, 'path' => $path] : null;
                }
            }

            return null;
        });
    }

    // --- Internals ----------------------------------------------------------------------

    /**
     * The organization's timeline, written inside that organization's
     * context: a Super Admin request has no organization of its own.
     */
    private function audit(int $organizationId, string $type, string $description, int $userId): void
    {
        OrganizationContext::runAs($organizationId, fn () => $this->timeline->record('organization', $organizationId, $type, $description, $userId));
    }

    /** @return array{ticket: array, messages: list<array>, attachments: array<int, list<array>>} attachments: message id => files */
    private function thread(array $ticket): array
    {
        $messages = $this->tickets->messages((int) $ticket['id']);
        $attachments = [];
        if ($this->attachments !== null) {
            OrganizationContext::runAs((int) $ticket['organization_id'], function () use ($messages, &$attachments): void {
                foreach ($messages as $m) {
                    $attachments[(int) $m['id']] = $this->attachments->forSubject(self::ATTACHMENT_SUBJECT, (int) $m['id']);
                }
            });
        }

        return ['ticket' => $ticket, 'messages' => $messages, 'attachments' => $attachments];
    }

    /**
     * Validates every file before anything is saved: allowed extension,
     * real content of that type, size, count.
     *
     * @param list<array> $files
     * @return list<array>
     */
    private function checkedFiles(array $files): array
    {
        $files = array_values(array_filter($files, fn ($f): bool => is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if ($files === []) {
            return [];
        }
        if ($this->attachments === null) {
            throw new ValidationException(['attachments' => ['platform_tickets.error.attachment_failed']]);
        }
        if (count($files) > self::ATTACHMENTS_PER_MESSAGE) {
            throw new ValidationException(['attachments' => ['platform_tickets.error.attachment_count']]);
        }
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::ATTACHMENT_MAX_BYTES) {
                throw new ValidationException(['attachments' => ['platform_tickets.error.attachment_size']]);
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
                throw new ValidationException(['attachments' => ['platform_tickets.error.attachment_failed']]);
            }
            $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $mime = (string) (mime_content_type((string) $file['tmp_name']) ?: '');
            if (!isset(self::ATTACHMENT_TYPES[$extension]) || !in_array($mime, self::ATTACHMENT_TYPES[$extension], true)) {
                throw new ValidationException(['attachments' => ['platform_tickets.error.attachment_type']]);
            }
        }

        return $files;
    }

    /**
     * Saves already-checked files on a message, inside the ticket's
     * organization. The message is already saved, so a file that cannot be
     * stored is counted and logged, never turned into a failed request
     * (which would invite a duplicate resend).
     *
     * @return int files not saved
     */
    private function storeFiles(int $organizationId, int $messageId, int $userId, array $files): int
    {
        if ($files === [] || $this->attachments === null) {
            return 0;
        }

        return OrganizationContext::runAs($organizationId, function () use ($organizationId, $messageId, $userId, $files): int {
            $failed = 0;
            foreach ($files as $file) {
                try {
                    $this->attachments->upload(self::ATTACHMENT_SUBJECT, $messageId, $organizationId, $userId, $file);
                } catch (ValidationException $e) {
                    $failed++;
                    error_log('platform ticket attachment not saved: ' . json_encode($e->errors));
                }
            }

            return $failed;
        });
    }

    private function body(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::BODY_MAX) {
            throw new ValidationException(['body' => ['platform_tickets.error.body']]);
        }

        return $body;
    }

    /** The ticket's creator learns about a platform reply or close through the announcement bell. */
    private function notify(array $ticket, array $sender, string $event): void
    {
        $creator = $ticket['created_by_user_id'] !== null ? $this->users->findById((int) $ticket['created_by_user_id']) : null;
        if ($creator === null || ($creator['status'] ?? '') !== 'active') {
            return;
        }
        $locale = in_array($creator['language'] ?? null, Translator::SUPPORTED_LOCALES, true) ? (string) $creator['language'] : 'en';
        $t = new Translator($locale, $this->langPath);
        $replace = ['id' => (string) $ticket['id'], 'subject' => (string) $ticket['subject']];
        try {
            $this->announcements->create(
                mb_substr($t->get('platform_tickets.notice.' . $event . '_title', $replace), 0, 200),
                $t->get('platform_tickets.notice.' . $event . '_body', $replace),
                'single',
                (int) $sender['id'],
                [['id' => (int) $creator['id'], 'organization_id' => (int) $ticket['organization_id']]],
            );
        } catch (\PDOException $e) {
            // The reply is already saved; a failed notice must not report it as failed.
            error_log('platform ticket notice not sent: ' . $e->getMessage());
        }
    }

    private function organizationAdmin(array $user): int
    {
        if (!$this->canUse($user)) {
            throw new AuthorizationException();
        }

        return (int) $user['organization_id'];
    }

    private function superAdmin(array $user): void
    {
        if (empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
