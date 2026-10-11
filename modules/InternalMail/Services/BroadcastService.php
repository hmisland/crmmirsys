<?php

declare(strict_types=1);

namespace Modules\InternalMail\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use Modules\InternalMail\ContactBroadcastRepository;

/**
 * "Send to all" per the explicit requirement — two different
 * mechanisms behind one concept, because the two audiences are
 * fundamentally different kinds of recipient:
 *
 * - Team: every org member has a system account and an Internal Mail
 *   inbox already, so a broadcast is just an ordinary MessageService
 *   compose() with every org member as a recipient.
 * - Customers: Contacts have no login, no inbox — a broadcast to them
 *   is a real outbound email via the same MailerInterface Phase 2
 *   built for verification/reset links (log/php_mail/smtp, whichever
 *   this deployment is configured for).
 *
 * No queue/background job system exists yet in this codebase — a
 * customer broadcast sends synchronously, in the same request, one
 * SMTP call per contact. Fine for tens or a few hundred contacts;
 * genuinely risks a slow request or a timeout for a very large list.
 * Documented plainly here and in docs/phase9-internal-mail.md — a
 * real queue is future infrastructure work, not something to fake in
 * this pass.
 */
final class BroadcastService
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly UserRepository $users,
        private readonly ContactRepository $contacts,
        private readonly ContactBroadcastRepository $broadcastLog,
        private readonly MailerInterface $mailer,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function broadcastToTeam(array $actingUser, string $subject, string $body): array
    {
        $this->authorize($actingUser, Permission::MAIL_BROADCAST_TEAM);

        $organizationId = (int) $actingUser['organization_id'];
        $recipientIds = [];
        foreach ($this->users->findAllInOrganization($organizationId) as $user) {
            if ((int) $user['id'] !== (int) $actingUser['id']) {
                $recipientIds[] = (int) $user['id'];
            }
        }

        if ($recipientIds === []) {
            throw new ValidationException(['recipients' => ['mail.error.no_team_members']]);
        }

        return $this->messages->compose($actingUser, $recipientIds, [], [], $subject, $body);
    }

    /** @return array{recipient_count: int, failed_count: int} */
    public function broadcastToCustomers(array $actingUser, string $subject, string $body): array
    {
        $this->authorize($actingUser, Permission::MAIL_BROADCAST_CUSTOMERS);

        if (trim($subject) === '') {
            throw new ValidationException(['subject' => ['mail.error.subject_required']]);
        }
        if (trim($body) === '') {
            throw new ValidationException(['body' => ['mail.error.body_required']]);
        }

        $contacts = $this->contacts->search([]);
        $sent = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            $email = trim((string) ($contact['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                $this->mailer->send($email, $subject, $body);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        if ($sent === 0 && $failed === 0) {
            throw new ValidationException(['recipients' => ['mail.error.no_customer_emails']]);
        }

        $this->broadcastLog->create((int) $actingUser['id'], trim($subject), trim($body), $sent, $failed);

        return ['recipient_count' => $sent, 'failed_count' => $failed];
    }

    public function recentBroadcasts(): array
    {
        return $this->broadcastLog->recent();
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
