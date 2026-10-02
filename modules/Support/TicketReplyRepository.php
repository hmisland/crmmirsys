<?php

declare(strict_types=1);

namespace Modules\Support;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class TicketReplyRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'ticket_replies';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM ticket_replies WHERE id = :id', ['id' => $id]);
    }

    /**
     * Correlated subquery for the author's name — own WHERE inside,
     * so explicit org-qualification is used rather than
     * tenantFindMany()'s automatic (first-WHERE-only) scoping, same
     * reasoning as TicketRepository::search().
     */
    public function forTicket(int $ticketId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped
            ? 'ticket_replies.organization_id = :org_id AND ticket_replies.ticket_id = :ticket_id'
            : 'ticket_replies.ticket_id = :ticket_id';
        $params = ['ticket_id' => $ticketId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        return $this->findMany(
            "SELECT ticket_replies.*,
                (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = ticket_replies.user_id) AS author_name
             FROM ticket_replies
             WHERE {$where}
             ORDER BY id",
            $params
        );
    }

    public function create(int $ticketId, int $userId, string $body, bool $isInternal): int
    {
        return $this->tenantInsertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'body' => $body,
            'is_internal' => $isInternal ? 1 : 0,
        ]);
    }
}
