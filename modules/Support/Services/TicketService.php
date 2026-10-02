<?php

declare(strict_types=1);

namespace Modules\Support\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Validation\Validator;
use Modules\Support\TicketRepository;

final class TicketService
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::TICKETS_VIEW);

        return $this->tickets->search($filters);
    }

    /** For the Dashboard's "tickets needing my response" widget. */
    public function openAssignedToUser(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::TICKETS_VIEW);

        return $this->tickets->openAssignedToUser((int) $actingUser['id']);
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::TICKETS_VIEW);

        return $this->requireTicket($id);
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::TICKETS_CREATE);

        $validator = Validator::make($input, ['subject' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $fields = $this->fieldsFromInput($input);
        $fields['created_by_user_id'] = (int) $actingUser['id'];
        if (empty($fields['assigned_to_user_id'])) {
            $fields['assigned_to_user_id'] = (int) $actingUser['id'];
        }

        $id = $this->tickets->create($fields);
        $this->timeline->record('ticket', $id, 'created', 'Ticket opened', (int) $actingUser['id']);

        return $this->tickets->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $this->requireTicket($id);
        $this->authorize($actingUser, Permission::TICKETS_EDIT);

        $validator = Validator::make($input, ['subject' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $this->tickets->updateTicket($id, $this->fieldsFromInput($input));
        $this->timeline->record('ticket', $id, 'updated', 'Ticket details updated', (int) $actingUser['id']);

        return $this->tickets->findById($id);
    }

    public function changeStatus(array $actingUser, int $id, string $status): array
    {
        $this->requireTicket($id);
        $this->authorize($actingUser, Permission::TICKETS_EDIT);

        if (!in_array($status, ['open', 'pending', 'resolved', 'closed'], true)) {
            throw new ValidationException(['status' => ['Invalid status.']]);
        }

        $fields = ['status' => $status];
        if ($status === 'resolved') {
            $fields['resolved_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'closed') {
            $fields['closed_at'] = date('Y-m-d H:i:s');
        }

        $this->tickets->updateTicket($id, $fields);
        $this->timeline->record('ticket', $id, 'status_changed', "Status changed to \"{$status}\"", (int) $actingUser['id']);

        return $this->tickets->findById($id);
    }

    public function assign(array $actingUser, int $id, ?int $assigneeUserId): array
    {
        $this->requireTicket($id);
        $this->authorize($actingUser, Permission::TICKETS_ASSIGN);

        $this->tickets->updateTicket($id, ['assigned_to_user_id' => $assigneeUserId]);
        $this->timeline->record('ticket', $id, 'assigned', 'Assignee changed', (int) $actingUser['id']);

        return $this->tickets->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->requireTicket($id);
        $this->authorize($actingUser, Permission::TICKETS_DELETE);

        $this->tickets->deleteTicket($id);
    }

    private function fieldsFromInput(array $input): array
    {
        return array_filter([
            'subject' => trim((string) $input['subject']),
            'description' => $this->orNull($input, 'description'),
            'priority' => in_array($input['priority'] ?? null, ['low', 'medium', 'high', 'urgent'], true) ? $input['priority'] : null,
            'category_id' => isset($input['category_id']) && $input['category_id'] !== '' ? (int) $input['category_id'] : null,
            'contact_id' => isset($input['contact_id']) && $input['contact_id'] !== '' ? (int) $input['contact_id'] : null,
            'assigned_to_user_id' => isset($input['assigned_to_user_id']) && $input['assigned_to_user_id'] !== '' ? (int) $input['assigned_to_user_id'] : null,
        ], fn ($v) => $v !== null);
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function requireTicket(int $id): array
    {
        $ticket = $this->tickets->findById($id);

        if ($ticket === null) {
            throw new NotFoundException('Ticket not found.');
        }

        return $ticket;
    }
}
