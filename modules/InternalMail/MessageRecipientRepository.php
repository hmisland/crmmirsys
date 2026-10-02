<?php

declare(strict_types=1);

namespace Modules\InternalMail;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class MessageRecipientRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'message_recipients';
    }

    /**
     * One row per message the user received (root messages AND
     * replies alike — no thread-grouping in this pass, a documented
     * simplification: a busy thread shows as several inbox rows
     * rather than one grouped row with an unread count, the way
     * Gmail-style clients do it).
     *
     * Correlated subqueries for subject/sender name, each with its
     * own WHERE — explicitly org-qualified rather than relying on
     * tenantFindMany()'s automatic first-WHERE-only scoping (the
     * established fix for this bug class, applied from the start).
     */
    /** Only this user's own, not-yet-trashed copies — To/Cc/Bcc all included: Bcc only hides the recipient's identity from *other* recipients, it doesn't hide the message from the Bcc'd person themselves. */
    public function inboxForUser(int $userId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped
            ? 'message_recipients.organization_id = :org_id AND message_recipients.recipient_user_id = :user_id AND message_recipients.is_deleted = 0'
            : 'message_recipients.recipient_user_id = :user_id AND message_recipients.is_deleted = 0';
        $params = ['user_id' => $userId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        return $this->findMany(
            "SELECT message_recipients.*,
                (SELECT subject FROM messages WHERE messages.id = message_recipients.message_id) AS subject,
                (SELECT created_at FROM messages WHERE messages.id = message_recipients.message_id) AS message_created_at,
                (SELECT thread_id FROM messages WHERE messages.id = message_recipients.message_id) AS thread_id,
                (SELECT CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))
                 FROM messages m JOIN users u ON u.id = m.sender_user_id
                 WHERE m.id = message_recipients.message_id) AS sender_name
             FROM message_recipients
             WHERE {$where}
             ORDER BY message_recipients.id DESC",
            $params
        );
    }

    /** This user's own trashed (from inbox) copies. */
    public function trashForUser(int $userId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped
            ? 'message_recipients.organization_id = :org_id AND message_recipients.recipient_user_id = :user_id AND message_recipients.is_deleted = 1'
            : 'message_recipients.recipient_user_id = :user_id AND message_recipients.is_deleted = 1';
        $params = ['user_id' => $userId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        return $this->findMany(
            "SELECT message_recipients.*,
                (SELECT subject FROM messages WHERE messages.id = message_recipients.message_id) AS subject,
                (SELECT created_at FROM messages WHERE messages.id = message_recipients.message_id) AS message_created_at,
                (SELECT thread_id FROM messages WHERE messages.id = message_recipients.message_id) AS thread_id,
                (SELECT CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))
                 FROM messages m JOIN users u ON u.id = m.sender_user_id
                 WHERE m.id = message_recipients.message_id) AS sender_name
             FROM message_recipients
             WHERE {$where}
             ORDER BY message_recipients.id DESC",
            $params
        );
    }

    /** Every recipient row, including Bcc — only for internal checks (e.g. "is this user a participant") that must see everyone, never for rendering a recipient list to a non-sender viewer. */
    public function forMessage(int $messageId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM message_recipients WHERE message_id = :message_id',
            ['message_id' => $messageId]
        );
    }

    /**
     * What's safe to SHOW a given viewer: every To/Cc recipient, plus
     * Bcc only if the viewer is the sender or that exact Bcc'd person
     * — the actual privacy boundary the amendment's Cc/Bcc fields
     * exist to enforce. Its own query (not a filter over forMessage())
     * because this one is specifically for display and needs the
     * recipient's name, which forMessage()'s raw rows don't carry.
     */
    public function visibleRecipientsFor(int $messageId, int $viewingUserId, bool $viewerIsSender): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped
            ? 'message_recipients.organization_id = :org_id AND message_recipients.message_id = :message_id'
            : 'message_recipients.message_id = :message_id';
        $params = ['message_id' => $messageId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        $rows = $this->findMany(
            "SELECT message_recipients.*,
                (SELECT CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))
                 FROM users u WHERE u.id = message_recipients.recipient_user_id) AS recipient_name
             FROM message_recipients WHERE {$where}",
            $params
        );

        return array_values(array_filter(
            $rows,
            fn (array $row) => $row['recipient_type'] !== 'bcc' || $viewerIsSender || (int) $row['recipient_user_id'] === $viewingUserId
        ));
    }

    public function unreadCountForUser(int $userId): int
    {
        $row = $this->tenantFindOne(
            'SELECT COUNT(*) AS c FROM message_recipients WHERE recipient_user_id = :user_id AND is_read = 0 AND is_deleted = 0',
            ['user_id' => $userId]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function create(int $messageId, int $recipientUserId, string $recipientType = 'to'): int
    {
        return $this->tenantInsertGetId(['message_id' => $messageId, 'recipient_user_id' => $recipientUserId, 'recipient_type' => $recipientType]);
    }

    public function markRead(int $messageId, int $userId): void
    {
        $this->tenantUpdate(
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'message_id = :message_id AND recipient_user_id = :user_id',
            ['message_id' => $messageId, 'user_id' => $userId]
        );
    }

    public function softDelete(int $messageId, int $userId): void
    {
        $this->tenantUpdate(
            ['is_deleted' => 1, 'deleted_at' => date('Y-m-d H:i:s')],
            'message_id = :message_id AND recipient_user_id = :user_id',
            ['message_id' => $messageId, 'user_id' => $userId]
        );
    }

    public function restore(int $messageId, int $userId): void
    {
        $this->tenantUpdate(
            ['is_deleted' => 0, 'deleted_at' => null],
            'message_id = :message_id AND recipient_user_id = :user_id',
            ['message_id' => $messageId, 'user_id' => $userId]
        );
    }
}
