<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * platform_tickets + platform_ticket_messages (migration 0090).
 * Organization-side reads always take the organization id explicitly;
 * the Super Admin list is the only cross-organization read (callers check
 * is_super_admin).
 */
final class PlatformTicketRepository extends BaseRepository
{
    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const CLOSED = 'closed';
    public const STATUSES = [self::OPEN, self::ANSWERED, self::CLOSED];

    public const SIDE_ORGANIZATION = 'organization';
    public const SIDE_PLATFORM = 'platform';

    public function create(int $organizationId, int $userId, string $subject, string $category, string $priority, string $body): int
    {
        $now = date('Y-m-d H:i:s');
        $id = $this->insertGetId('platform_tickets', [
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
            'subject' => $subject,
            'category' => $category,
            'priority' => $priority,
            'status' => self::OPEN,
            'last_activity_at' => $now,
            'created_at' => $now,
        ]);
        $this->addMessage($id, $userId, self::SIDE_ORGANIZATION, $body);

        return $id;
    }

    public function addMessage(int $ticketId, int $userId, string $side, string $body): int
    {
        return $this->insertGetId('platform_ticket_messages', [
            'ticket_id' => $ticketId,
            'author_user_id' => $userId,
            'author_side' => $side,
            'body' => $body,
        ]);
    }

    /** New status after a message or a close / reopen; also moves the ticket up the lists. */
    public function setStatus(int $id, string $status, ?int $closedBy): void
    {
        $this->update('platform_tickets', [
            'status' => $status,
            'last_activity_at' => date('Y-m-d H:i:s'),
            'closed_at' => $status === self::CLOSED ? date('Y-m-d H:i:s') : null,
            'closed_by_user_id' => $status === self::CLOSED ? $closedBy : null,
        ], 'id = :id', ['id' => $id]);
    }

    public function setPriority(int $id, string $priority): void
    {
        $this->update('platform_tickets', ['priority' => $priority], 'id = :id', ['id' => $id]);
    }

    /** One ticket of one organization (null when it belongs to another organization). */
    public function findForOrganization(int $id, int $organizationId): ?array
    {
        return $this->findOne(
            self::SELECT . ' WHERE t.id = :id AND t.organization_id = :org',
            ['id' => $id, 'org' => $organizationId]
        );
    }

    /** Any ticket (Super Admin). */
    public function find(int $id): ?array
    {
        return $this->findOne(self::SELECT . ' WHERE t.id = :id', ['id' => $id]);
    }

    /** Locks the ticket row for a status change (inside a transaction). */
    public function lock(int $id): ?array
    {
        return $this->findOne('SELECT * FROM platform_tickets WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** @return list<array> the thread, oldest first */
    public function messages(int $ticketId): array
    {
        return $this->findMany(
            "SELECT m.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS author_name
             FROM platform_ticket_messages m LEFT JOIN users u ON u.id = m.author_user_id
             WHERE m.ticket_id = :id ORDER BY m.id",
            ['id' => $ticketId]
        );
    }

    /** One organization's tickets: open and answered first, then closed; latest activity first. */
    public function organizationPage(int $organizationId, string $status, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions(['status' => $status, 'organization_id' => $organizationId]);

        return $this->findMany(
            self::SELECT . " WHERE {$where}
             ORDER BY (t.status = 'closed') ASC, t.last_activity_at DESC, t.id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    /**
     * Super Admin list: waiting for the platform (open) first — urgent first,
     * then oldest activity first — then answered, then closed (latest first).
     *
     * @param array{status?: string, organization_id?: int, q?: string} $filters
     */
    public function adminPage(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany(
            self::SELECT . " WHERE {$where}
             ORDER BY FIELD(t.status, 'open', 'answered', 'closed'),
                      CASE WHEN t.status = 'open' THEN FIELD(t.priority, 'urgent', 'high', 'normal') END,
                      CASE WHEN t.status = 'open' THEN t.last_activity_at END ASC,
                      t.last_activity_at DESC, t.id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM platform_tickets t JOIN organizations o ON o.id = t.organization_id WHERE ' . $where, $params)['c'] ?? 0);
    }

    /** Tickets waiting for the platform team (Super Admin menu count). */
    public function openCount(): int
    {
        return (int) ($this->findOne("SELECT COUNT(*) AS c FROM platform_tickets WHERE status = 'open'")['c'] ?? 0);
    }

    /** Tickets of one organization waiting for the organization (answered). */
    public function answeredCountForOrganization(int $organizationId): int
    {
        return (int) ($this->findOne(
            "SELECT COUNT(*) AS c FROM platform_tickets WHERE organization_id = :org AND status = 'answered'",
            ['org' => $organizationId]
        )['c'] ?? 0);
    }

    /** New tickets a user opened since a moment (rate limit). */
    public function createdByUserSince(int $userId, string $since): int
    {
        return (int) ($this->findOne(
            'SELECT COUNT(*) AS c FROM platform_tickets WHERE created_by_user_id = :u AND created_at >= :since',
            ['u' => $userId, 'since' => $since]
        )['c'] ?? 0);
    }

    /** Runs $work in a transaction (joins the caller's, if one is open). */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    private const SELECT = "SELECT t.*, o.name AS organization_name,
            TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS creator_name, u.email AS creator_email,
            (SELECT COUNT(*) FROM platform_ticket_messages m WHERE m.ticket_id = t.id) AS message_count
        FROM platform_tickets t
        JOIN organizations o ON o.id = t.organization_id
        LEFT JOIN users u ON u.id = t.created_by_user_id";

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = '1 = 1';
        $params = [];
        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $where .= ' AND t.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['organization_id'])) {
            $where .= ' AND t.organization_id = :org';
            $params['org'] = (int) $filters['organization_id'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (t.subject LIKE :q OR o.name LIKE :q2' . (ctype_digit($q) ? ' OR t.id = :qid' : '') . ')';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
            $params['q2'] = $params['q'];
            if (ctype_digit($q)) {
                $params['qid'] = (int) $q;
            }
        }

        return [$where, $params];
    }
}
