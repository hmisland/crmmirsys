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
 */
final class PlatformTicketService
{
    public const CATEGORIES = ['general', 'technical', 'account', 'modules', 'feature', 'other'];
    public const PRIORITIES = ['normal', 'high', 'urgent'];
    public const SUBJECT_MAX = 200;
    public const BODY_MAX = 5000;
    /** New tickets one user may open per hour. */
    public const HOURLY_LIMIT = 10;

    public function __construct(
        private readonly PlatformTicketRepository $tickets,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly AdminAnnouncementRepository $announcements,
        private readonly UserRepository $users,
        private readonly string $langPath,
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

    /** @return int the new ticket id */
    public function create(array $user, array $input): int
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
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->tickets->createdByUserSince((int) $user['id'], date('Y-m-d H:i:s', time() - 3600)) >= self::HOURLY_LIMIT) {
            throw new ValidationException(['ticket' => ['platform_tickets.error.rate_limited']]);
        }

        $id = $this->tickets->atomically(fn (): int => $this->tickets->create($organizationId, (int) $user['id'], $subject, $category, $priority, $body));
        $this->timeline->record('organization', $organizationId, 'platform_ticket.created', 'Ticket #' . $id . ' sent to the platform team', (int) $user['id']);

        return $id;
    }

    /** @return array{ticket: array, messages: list<array>} */
    public function showForOrganization(array $user, int $id): array
    {
        $ticket = $this->tickets->findForOrganization($id, $this->organizationAdmin($user));
        if ($ticket === null) {
            throw new NotFoundException();
        }

        return ['ticket' => $ticket, 'messages' => $this->tickets->messages($id)];
    }

    public function replyAsOrganization(array $user, int $id, string $body): void
    {
        $organizationId = $this->organizationAdmin($user);
        $body = $this->body($body);
        $this->tickets->atomically(function () use ($user, $id, $organizationId, $body): void {
            $ticket = $this->tickets->lock($id);
            if ($ticket === null || (int) $ticket['organization_id'] !== $organizationId) {
                throw new NotFoundException();
            }
            $this->tickets->addMessage($id, (int) $user['id'], PlatformTicketRepository::SIDE_ORGANIZATION, $body);
            $this->tickets->setStatus($id, PlatformTicketRepository::OPEN, null);
        });
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
        $this->timeline->record('organization', $organizationId, 'platform_ticket.closed', 'Ticket #' . $id . ' closed by the organization', (int) $user['id']);
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

        return ['ticket' => $ticket, 'messages' => $this->tickets->messages($id)];
    }

    /** Platform reply; "close" also closes the ticket in the same step. */
    public function replyAsPlatform(array $user, int $id, string $body, bool $close): void
    {
        $this->superAdmin($user);
        $body = $this->body($body);
        $ticket = $this->tickets->atomically(function () use ($user, $id, $body, $close): array {
            $ticket = $this->tickets->lock($id);
            if ($ticket === null) {
                throw new NotFoundException();
            }
            $this->tickets->addMessage($id, (int) $user['id'], PlatformTicketRepository::SIDE_PLATFORM, $body);
            $this->tickets->setStatus($id, $close ? PlatformTicketRepository::CLOSED : PlatformTicketRepository::ANSWERED, $close ? (int) $user['id'] : null);

            return $ticket;
        });
        $this->timeline->record('organization', (int) $ticket['organization_id'], 'platform_ticket.answered', 'Ticket #' . $id . ' answered by the platform team', (int) $user['id']);
        $this->notify($ticket, $user, $close ? 'answered_closed' : 'answered');
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
        $this->timeline->record('organization', (int) $ticket['organization_id'], 'platform_ticket.' . ($status === PlatformTicketRepository::CLOSED ? 'closed' : 'reopened'), 'Ticket #' . $id . ' ' . ($status === PlatformTicketRepository::CLOSED ? 'closed' : 'reopened') . ' by the platform team', (int) $user['id']);
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

    // --- Internals ----------------------------------------------------------------------

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
