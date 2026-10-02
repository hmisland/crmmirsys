<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\FinancialPeriodRepository;

final class FinancialPeriodService
{
    public function __construct(
        private readonly FinancialPeriodRepository $periods,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_VIEW);

        return $this->periods->all();
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_CLOSE);

        $startsOn = (string) ($input['starts_on'] ?? '');
        $endsOn = (string) ($input['ends_on'] ?? '');

        if ($startsOn === '' || $endsOn === '') {
            throw new ValidationException(['starts_on' => ['Both a start and end date are required.']]);
        }

        if ($startsOn > $endsOn) {
            throw new ValidationException(['ends_on' => ['End date must be on or after the start date.']]);
        }

        if ($this->periods->overlapping($startsOn, $endsOn) !== []) {
            throw new ValidationException(['starts_on' => ['This range overlaps an existing financial period.']]);
        }

        $id = $this->periods->create(['starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => 'open']);

        return $this->periods->findById($id);
    }

    public function close(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_CLOSE);

        $period = $this->requirePeriod($id);
        if ($period['status'] === 'closed') {
            throw new ValidationException(['status' => ['This period is already closed.']]);
        }

        $this->periods->updatePeriod($id, [
            'status' => 'closed',
            'closed_by_user_id' => $actingUser['id'],
            'closed_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->periods->findById($id);
    }

    public function reopen(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_REOPEN);

        $period = $this->requirePeriod($id);
        if ($period['status'] === 'open') {
            throw new ValidationException(['status' => ['This period is already open.']]);
        }

        $this->periods->updatePeriod($id, ['status' => 'open', 'closed_by_user_id' => null, 'closed_at' => null]);

        return $this->periods->findById($id);
    }

    /**
     * Called from InvoiceService/PaymentService/CreditNoteService
     * before any write whose own date falls inside a period — no
     * acting-user/permission check here, this is a pure fact-check
     * any write path can consult.
     */
    public function isDateLocked(string $date): bool
    {
        return $this->periods->closedPeriodCovering($date) !== null;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function requirePeriod(int $id): array
    {
        $period = $this->periods->findById($id);

        if ($period === null) {
            throw new NotFoundException('Financial period not found.');
        }

        return $period;
    }
}
