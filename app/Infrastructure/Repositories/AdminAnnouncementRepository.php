<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * admin_announcements + admin_announcement_recipients: Super Admin → org
 * admin messages. Not tenant-scoped: the Super Admin reads across all
 * organizations, and an org admin only ever reads rows by their own user id.
 */
final class AdminAnnouncementRepository extends BaseRepository
{
    /** Active users holding the org_admin role, with their organization name. */
    public function orgAdmins(): array
    {
        return $this->findMany(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.organization_id, o.name AS organization_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id AND r.`key` = 'org_admin'
             INNER JOIN organizations o ON o.id = u.organization_id
             WHERE u.status = 'active'
             ORDER BY o.name, u.first_name, u.last_name"
        );
    }

    public function isOrgAdmin(int $userId): bool
    {
        return $this->findOne(
            "SELECT 1 AS ok FROM users u INNER JOIN roles r ON r.id = u.role_id AND r.`key` = 'org_admin'
             WHERE u.id = :id AND u.organization_id IS NOT NULL",
            ['id' => $userId]
        ) !== null;
    }

    /** @param array<int, array{id: int, organization_id: ?int}> $recipients */
    public function create(string $title, string $body, string $audience, int $createdBy, array $recipients): int
    {
        return $this->transaction(function () use ($title, $body, $audience, $createdBy, $recipients) {
            $id = $this->insertGetId('admin_announcements', [
                'title' => $title,
                'body' => $body,
                'audience' => $audience,
                'created_by' => $createdBy,
            ]);
            foreach ($recipients as $r) {
                $this->insertGetId('admin_announcement_recipients', [
                    'announcement_id' => $id,
                    'user_id' => (int) $r['id'],
                    'organization_id' => $r['organization_id'] !== null ? (int) $r['organization_id'] : null,
                ]);
            }

            return $id;
        });
    }

    /** Sent announcements, newest first, with recipient/seen/deleted counts. */
    public function sent(int $limit, int $offset): array
    {
        return $this->findMany(
            'SELECT a.*,
                    COUNT(r.id) AS recipients,
                    SUM(r.seen_at IS NOT NULL) AS seen,
                    SUM(r.deleted_at IS NOT NULL) AS deleted
             FROM admin_announcements a
             LEFT JOIN admin_announcement_recipients r ON r.announcement_id = a.id
             GROUP BY a.id
             ORDER BY a.id DESC
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
    }

    public function countSent(): int
    {
        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM admin_announcements')['c'] ?? 0);
    }

    public function find(int $id): ?array
    {
        return $this->findOne('SELECT * FROM admin_announcements WHERE id = :id', ['id' => $id]);
    }

    /** Per-recipient report for one announcement. */
    public function recipients(int $announcementId): array
    {
        return $this->findMany(
            'SELECT r.*, u.first_name, u.last_name, u.email, o.name AS organization_name
             FROM admin_announcement_recipients r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN organizations o ON o.id = r.organization_id
             WHERE r.announcement_id = :id
             ORDER BY o.name, u.first_name',
            ['id' => $announcementId]
        );
    }

    public function deleteAnnouncement(int $id): bool
    {
        return $this->delete('admin_announcements', 'id = :id', ['id' => $id]) > 0;
    }

    /** One user's messages still in their bell, newest first. */
    public function inbox(int $userId, int $limit = 50): array
    {
        return $this->findMany(
            'SELECT a.id, a.title, a.body, a.created_at, r.seen_at
             FROM admin_announcement_recipients r
             INNER JOIN admin_announcements a ON a.id = r.announcement_id
             WHERE r.user_id = :user_id AND r.deleted_at IS NULL
             ORDER BY a.id DESC
             LIMIT ' . max(1, min($limit, 200)),
            ['user_id' => $userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) ($this->findOne(
            'SELECT COUNT(*) AS c FROM admin_announcement_recipients
             WHERE user_id = :user_id AND deleted_at IS NULL AND seen_at IS NULL',
            ['user_id' => $userId]
        )['c'] ?? 0);
    }

    public function markSeen(int $announcementId, int $userId): bool
    {
        return $this->update(
            'admin_announcement_recipients',
            ['seen_at' => date('Y-m-d H:i:s')],
            'announcement_id = :aid AND user_id = :uid AND seen_at IS NULL',
            ['aid' => $announcementId, 'uid' => $userId]
        ) > 0;
    }

    public function removeForUser(int $announcementId, int $userId): bool
    {
        return $this->update(
            'admin_announcement_recipients',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'announcement_id = :aid AND user_id = :uid AND deleted_at IS NULL',
            ['aid' => $announcementId, 'uid' => $userId]
        ) > 0;
    }
}
