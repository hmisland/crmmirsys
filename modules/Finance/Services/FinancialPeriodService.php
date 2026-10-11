<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\FinancialPeriodRepository;
use Modules\Finance\LockConflict;

final class FinancialPeriodService
{
    public function __construct(
        private readonly FinancialPeriodRepository $periods,
        private readonly AuthorizationEngine $authorization,
        // Stage 5.2 (L4): close / reopen audit events (subject "financial_period"), same transaction.
        private readonly ?TimelineService $timeline = null,
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

        // L5: the overlap check is a locking read in the same transaction as the insert.
        return LockConflict::guard(fn () => $this->periods->atomically(function () use ($startsOn, $endsOn): array {
            if ($this->periods->overlappingForUpdate($startsOn, $endsOn) !== []) {
                throw new ValidationException(['starts_on' => ['This range overlaps an existing financial period.']]);
            }

            $id = $this->periods->create(['starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => 'open']);

            return $this->periods->findById($id);
        }));
    }

    public function close(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_CLOSE);

        $this->requirePeriod($id);

        // L5: exclusive row lock, status re-checked under it — two concurrent closes
        // can't both succeed, and a close waits for in-flight writes in this period.
        return LockConflict::guard(fn () => $this->periods->atomically(function () use ($actingUser, $id): array {
            $period = $this->periods->lockForUpdate($id) ?? throw new NotFoundException('Financial period not found.');
            if ($period['status'] === 'closed') {
                throw new ValidationException(['status' => ['This period is already closed.']]);
            }

            $this->periods->updatePeriod($id, [
                'status' => 'closed',
                'closed_by_user_id' => $actingUser['id'],
                'closed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->timeline?->record('financial_period', $id, 'period_closed', 'Financial period ' . $period['starts_on'] . ' – ' . $period['ends_on'] . ' closed', (int) $actingUser['id']);

            return $this->periods->findById($id);
        }));
    }

    public function reopen(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_REOPEN);

        $this->requirePeriod($id);

        // L5: same exclusive row lock and re-check as close().
        return LockConflict::guard(fn () => $this->periods->atomically(function () use ($actingUser, $id): array {
            $period = $this->periods->lockForUpdate($id) ?? throw new NotFoundException('Financial period not found.');
            if ($period['status'] === 'open') {
                throw new ValidationException(['status' => ['This period is already open.']]);
            }

            $this->periods->updatePeriod($id, ['status' => 'open', 'closed_by_user_id' => null, 'closed_at' => null]);
            // The previous closer / time are cleared on the row; the timeline keeps the history.
            $this->timeline?->record('financial_period', $id, 'period_reopened', 'Financial period ' . $period['starts_on'] . ' – ' . $period['ends_on'] . ' reopened', (int) $actingUser['id']);

            return $this->periods->findById($id);
        }));
    }

    /**
     * L5 — the check every financial write makes INSIDE its own transaction,
     * after locking its document row: a shared locking read of the covering
     * period rows (FinancialPeriodRepository::isDateLockedForUpdate). The
     * earlier isDateLocked() remains a fast pre-check / page flag only.
     */
    public function assertOpenForWrite(string $date, string $field, string $message): void
    {
        if ($this->periods->isDateLockedForUpdate($date)) {
            throw new ValidationException([$field => [$message]]);
        }
    }

    /**
     * Stage 5.2 (L4): close / reopen history of every period, newest first — only with
     * financial_periods.view. @return array<int, list<array>> period id => events
     */
    public function history(array $actingUser, array $periodIds): array
    {
        $this->authorize($actingUser, Permission::FINANCIAL_PERIODS_VIEW);
        $history = [];
        foreach ($periodIds as $periodId) {
            $events = array_values(array_filter(
                $this->timeline?->forSubject('financial_period', (int) $periodId) ?? [],
                fn (array $e) => in_array($e['type'] ?? '', ['period_closed', 'period_reopened'], true)
            ));
            if ($events !== []) {
                $history[(int) $periodId] = $events;
            }
        }

        return $history;
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
