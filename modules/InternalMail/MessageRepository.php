<?php

declare(strict_types=1);

namespace Modules\InternalMail;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class MessageRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'messages';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM messages WHERE id = :id', ['id' => $id]);
    }

    /**
     * Every message in a thread, oldest first: the root plus every
     * reply, with the sender's name attached via a correlated
     * subquery (its own WHERE, so explicitly org-qualified rather
     * than relying on tenantFindMany()'s automatic first-WHERE-only
     * scoping — the established fix for this bug class).
     */
    public function forThread(int $rootId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped
            ? 'messages.organization_id = :org_id AND (messages.id = :root_id OR messages.thread_id = :root_id2)'
            : '(messages.id = :root_id OR messages.thread_id = :root_id2)';
        $params = ['root_id' => $rootId, 'root_id2' => $rootId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        return $this->findMany(
            "SELECT messages.*,
                (SELECT CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))
                 FROM users u WHERE u.id = messages.sender_user_id) AS sender_name
             FROM messages
             WHERE {$where}
             ORDER BY messages.id",
            $params
        );
    }

    /** Every message the user sent (root and replies alike), newest first — sent only, never drafts, never the sender's own trashed copies. */
    public function sentByUser(int $userId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM messages WHERE sender_user_id = :user_id AND status = 'sent' AND deleted_by_sender_at IS NULL ORDER BY id DESC",
            ['user_id' => $userId]
        );
    }

    /** This user's own drafts, newest first. */
    public function draftsByUser(int $userId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM messages WHERE sender_user_id = :user_id AND status = 'draft' AND deleted_by_sender_at IS NULL ORDER BY id DESC",
            ['user_id' => $userId]
        );
    }

    /** This user's own trashed-from-sent messages, newest first. */
    public function senderTrash(int $userId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM messages WHERE sender_user_id = :user_id AND deleted_by_sender_at IS NOT NULL ORDER BY id DESC",
            ['user_id' => $userId]
        );
    }

    /** Every sent message linked to a given CRM record — for surfacing on that record's own page, beyond the Timeline summary line. */
    public function forLinkable(string $linkableType, int $linkableId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM messages WHERE linkable_type = :linkable_type AND linkable_id = :linkable_id AND status = 'sent' ORDER BY id DESC",
            ['linkable_type' => $linkableType, 'linkable_id' => $linkableId]
        );
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateMessage(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    /** The sender's own "delete" — hides it from their Sent/Drafts view only; recipients keep their own copies untouched. */
    public function softDeleteBySender(int $id): void
    {
        $this->tenantUpdate(['deleted_by_sender_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    public function restoreBySender(int $id): void
    {
        $this->tenantUpdate(['deleted_by_sender_at' => null], 'id = :id', ['id' => $id]);
    }
}
