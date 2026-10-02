<?php

declare(strict_types=1);

namespace Modules\Support\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Support\TicketReplyRepository;

final class TicketReplyService
{
    public function __construct(
        private readonly TicketReplyRepository $replies,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function forTicket(int $ticketId): array
    {
        return $this->replies->forTicket($ticketId);
    }

    public function add(array $actingUser, int $ticketId, string $body, bool $isInternal): array
    {
        if (!$this->authorization->can($actingUser, Permission::TICKETS_EDIT)) {
            throw new AuthorizationException();
        }

        if (trim($body) === '') {
            throw new ValidationException(['body' => ['Reply cannot be empty.']]);
        }

        $id = $this->replies->create($ticketId, (int) $actingUser['id'], trim($body), $isInternal);

        return $this->replies->findById($id) ?? [];
    }
}
