<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\AttachmentService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use Modules\Finance\AllocationOptionsRepository;
use Modules\Finance\DuplicateSubmission;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\Money;
use Modules\Finance\SubmissionKeyRepository;

use function App\Infrastructure\Theme\t;

/**
 * Expenses (docs/project-finance-design.md, Phase 4: E1–E12, D-P1–D-P3).
 *
 * An expense is an ACTUAL cost: one positive amount that already excludes
 * recoverable tax (E3). It never touches revenue, invoices, payments, credit
 * notes, installments or contract figures.
 *
 * - Access: finance.expenses.view / finance.expenses.manage (module keys,
 *   registered by FinanceModule), always with record scope (owner / team /
 *   department), tenant-scoped repositories.
 * - Project (E1, E7, E9, D-P1): one nullable project_id; setting, changing or
 *   removing it needs projects.edit on the project(s) involved (read through
 *   RecordTypeRegistry::find('project')); a new attribution never goes to a
 *   cancelled project.
 * - Lifecycle: recorded → void (reason); no delete; edits / void / receipts
 *   only while the relevant dates are in OPEN financial periods, checked with
 *   a locking read (D-P3) inside the operation's transaction, after the row lock.
 * - Corrections (E8, D-P2): rows pointing at an ORIGINAL; increase / decrease
 *   of a positive amount; they copy the original's currency, project,
 *   category and scope (owner / team / department) so they are seen exactly
 *   where the original is; the original's net (original ± recorded
 *   corrections) never goes below zero — checked under a lock on the original.
 * - Timeline: details on the expense; generic wording on the project (no
 *   amounts — project viewers may not hold finance.expenses.view).
 * - Contract REFERENCE (Stage 5.1): one nullable contract_id, informational
 *   only — no calculation reads it, and a project is never derived from it
 *   (Contract ↔ Project is N:N). Setting / changing it needs the contract
 *   viewable through RecordTypeRegistry::find('contract'); it is edited under
 *   the same rules as any field (open period, recorded, frozen while the
 *   original has recorded corrections) and corrections copy it.
 */
final class ExpenseService
{
    public const VIEW = 'finance.expenses.view';
    public const MANAGE = 'finance.expenses.manage';
    public const CATEGORIES = ['labor', 'procurement', 'contractor', 'shipping', 'services', 'other'];
    public const DIRECTIONS = ['increase', 'decrease'];
    public const REASON_MAX = 450;
    public const SUBJECT = 'expense';
    /** Projects' key, referenced by name (as Finance already does for other modules' keys). */
    private const PROJECT_EDIT = 'projects.edit';
    private const PROJECT_TYPE = 'project';
    private const CONTRACT_TYPE = 'contract';

    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly AttachmentService $attachments,
        private readonly ?RecordTypeRegistry $recordTypes = null,
        private readonly ContactRepository $contacts = new ContactRepository(),
        private readonly CompanyRepository $companies = new CompanyRepository(),
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
        private readonly AllocationOptionsRepository $allocationOptions = new AllocationOptionsRepository(),
        private readonly SubmissionKeyRepository $submissionKeys = new SubmissionKeyRepository(),
    ) {
    }

    // ---- Reading -------------------------------------------------------------

    /** @return array{rows: array, total: int} */
    public function listPage(array $actingUser, array $filters, int $limit, int $offset): array
    {
        $scope = $this->authorization->scopeFor($actingUser, self::VIEW);
        if ($scope === null) {
            throw new AuthorizationException();
        }
        $filters = array_intersect_key($filters, array_flip(['status', 'category', 'currency_id', 'project_id', 'date_from', 'date_to']));
        match ($scope) {
            Scope::OWN => $filters['owner_id'] = (int) $actingUser['id'],
            Scope::TEAM => $filters['team_id'] = $actingUser['team_id'] ?? null,
            Scope::DEPARTMENT => $filters['department_id'] = $actingUser['department_id'] ?? null,
            default => null,
        };

        return $this->expenses->search($filters, $limit, $offset);
    }

    /** Page-level: may this user create expenses at all (the record-level checks still apply per action). */
    public function canManageAny(array $actingUser): bool
    {
        return $this->authorization->can($actingUser, self::MANAGE);
    }

    /** The expense if this user may view it: another organization / unknown → 404, out of scope → 403. */
    public function find(array $actingUser, int $id): array
    {
        $expense = $id > 0 ? $this->expenses->findById($id) : null;
        if ($expense === null) {
            throw new NotFoundException('Expense not found.');
        }
        if (!$this->authorization->can($actingUser, self::VIEW, $expense)) {
            throw new AuthorizationException();
        }

        return $expense;
    }

    /** Everything the detail page needs, with each action flagged only when the server would accept it. */
    public function details(array $actingUser, int $id): array
    {
        $expense = $this->find($actingUser, $id);
        $isCorrection = $expense['entry_type'] === 'correction';
        $original = $isCorrection ? $this->expenses->findById((int) $expense['correction_of_id']) : $expense;
        $corrections = $isCorrection ? [] : $this->expenses->correctionsOf((int) $expense['id']);
        $manage = $this->authorization->can($actingUser, self::MANAGE, $expense);
        $recorded = $expense['status'] === 'recorded';
        $periodOpen = !$this->isLocked((string) $expense['expense_date']);
        $hasRecordedCorrections = array_filter($corrections, fn (array $c) => $c['status'] === 'recorded') !== [];

        return [
            'expense' => $expense,
            'original' => $original,
            'corrections' => $corrections,
            'net' => $isCorrection || $original === null ? null : $this->net($original, $corrections),
            'project' => $this->projectView($actingUser, $expense['project_id'] !== null ? (int) $expense['project_id'] : null),
            'contract' => $this->contractView($actingUser, ($expense['contract_id'] ?? null) !== null ? (int) $expense['contract_id'] : null),
            'payee' => $this->payeeLabel($expense),
            'attachments' => $this->attachments->forSubject(self::SUBJECT, (int) $expense['id']),
            'timeline' => $this->timeline->forSubject(self::SUBJECT, (int) $expense['id']),
            'can_edit' => $manage && !$isCorrection && $recorded && $periodOpen,
            // A correction is voided under D-P2 (rights on its ORIGINAL and its project).
            'can_void' => $recorded && $periodOpen && ($isCorrection
                ? ($original !== null && $this->canCorrect($actingUser, $original))
                : ($manage && !$hasRecordedCorrections)),
            'can_correct' => !$isCorrection && $recorded && !$periodOpen && $this->canCorrect($actingUser, $expense),
            'can_manage_receipts' => $manage && $recorded && $periodOpen,
        ];
    }

    /**
     * Project Costs panel (E10): net actual cost of the expenses this user may
     * view, per currency (never a cross-currency total), plus the latest
     * originals. Restricted expenses are neither listed nor summed.
     *
     * @return array{totals: array<int, string>, recent: array, can_create: bool}
     */
    public function projectPanel(array $actingUser, array $project, \Closure $can): array
    {
        $totals = [];
        $recent = [];
        foreach ($this->expenses->recordedForProject((int) $project['id']) as $row) {
            if (!$this->authorization->can($actingUser, self::VIEW, $row)) {
                continue;
            }
            $cur = (int) $row['currency_id'];
            $totals[$cur] = bcadd($totals[$cur] ?? '0', $this->signed($row), 18);
            if ($row['entry_type'] === 'expense') {
                $recent[] = $row;
            }
        }
        usort($recent, fn (array $a, array $b) => [$b['expense_date'], $b['id']] <=> [$a['expense_date'], $a['id']]);

        return [
            'totals' => $totals,
            'recent' => array_slice($recent, 0, 10),
            'can_create' => $can(self::MANAGE) && $this->canAttachTo($actingUser, $project),
        ];
    }

    /** "New expense" from a project (D-P1): prefill only; every check runs again on submit. */
    public function draftFromProject(array $actingUser, int $projectId): array
    {
        if (!$this->authorization->can($actingUser, self::MANAGE)) {
            throw new AuthorizationException();
        }
        $project = $this->project($actingUser, $projectId);
        if ($project === null) {
            throw new NotFoundException('Project not found.');
        }
        $this->assertAttachable($actingUser, $project);

        return ['project_id' => (string) $projectId, 'expense_date' => \App\Infrastructure\Time\OrganizationTimezone::shared()->today()];
    }

    /** @return array{id: int, label: string, url: ?string}|null the project as this user may see it */
    public function projectView(array $actingUser, ?int $projectId): ?array
    {
        if ($projectId === null) {
            return null;
        }
        $project = $this->project($actingUser, $projectId);

        return [
            'id' => $projectId,
            'label' => $project !== null ? $this->projectLabel($project) : null,
            'url' => $project !== null ? $this->recordTypes?->get(self::PROJECT_TYPE)?->showUrl($projectId) : null,
        ];
    }

    /** @return array{id: int, label: ?string, url: ?string}|null the referenced contract as this user may see it */
    public function contractView(array $actingUser, ?int $contractId): ?array
    {
        if ($contractId === null) {
            return null;
        }
        $contract = $this->contract($actingUser, $contractId);

        return [
            'id' => $contractId,
            'label' => $contract !== null ? $this->contractLabel($contract) : null,
            'url' => $contract !== null ? $this->recordTypes?->get(self::CONTRACT_TYPE)?->showUrl($contractId) : null,
        ];
    }

    /** Whether the "Allocate to project" selector applies: Projects available and projects.view held. */
    public function projectsAvailable(array $actingUser): bool
    {
        return $this->recordTypes?->canView(self::PROJECT_TYPE, $actingUser) ?? false;
    }

    /** Whether the "Contract reference" selector applies: Contracts available and contracts.view held. */
    public function contractsAvailable(array $actingUser): bool
    {
        return $this->recordTypes?->canView(self::CONTRACT_TYPE, $actingUser) ?? false;
    }

    /**
     * "Allocate to project" choices (Stage 5.1): projects the server would accept
     * for a NEW attribution — viewable (same rule as find('project')), projects.edit
     * on the row (E7), not cancelled (E9). Empty when Projects isn't available.
     *
     * @return array<int, string> project id => label, newest first
     */
    public function projectOptions(array $actingUser): array
    {
        $type = $this->recordTypes?->get(self::PROJECT_TYPE);
        if ($type === null || !$this->recordTypes->canView(self::PROJECT_TYPE, $actingUser)) {
            return [];
        }
        $options = [];
        foreach ($this->allocationOptions->projects() as $row) {
            if ($row['status'] !== 'cancelled'
                && $this->authorization->can($actingUser, (string) $type->viewPermission, $row)
                && $this->authorization->can($actingUser, self::PROJECT_EDIT, $row)) {
                $options[(int) $row['id']] = $this->projectLabel($row);
            }
        }

        return $options;
    }

    /**
     * "Contract reference" choices (Stage 5.1): contracts viewable by this user
     * (same rule as find('contract')), any status. Empty when Contracts isn't available.
     *
     * @return array<int, string> contract id => label, newest first
     */
    public function contractOptions(array $actingUser): array
    {
        $type = $this->recordTypes?->get(self::CONTRACT_TYPE);
        if ($type === null || !$this->recordTypes->canView(self::CONTRACT_TYPE, $actingUser)) {
            return [];
        }
        $options = [];
        foreach ($this->allocationOptions->contracts() as $row) {
            if ($this->authorization->can($actingUser, (string) $type->viewPermission, $row)) {
                $options[(int) $row['id']] = $this->contractLabel($row);
            }
        }

        return $options;
    }

    /** The project row if this user may view it (Projects available, projects.view, organization, record scope), else null. */
    public function viewableProject(array $actingUser, int $projectId): ?array
    {
        return $this->project($actingUser, $projectId);
    }

    public function projectUrl(int $projectId): ?string
    {
        return $this->recordTypes?->get(self::PROJECT_TYPE)?->showUrl($projectId);
    }

    // ---- Writing -------------------------------------------------------------

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, self::MANAGE)) {
            throw new AuthorizationException();
        }
        $fields = $this->validated($input, null);
        $projectId = $this->requestedProject($input);
        $project = null;
        if ($projectId !== null) {
            $project = $this->project($actingUser, $projectId);
            if ($project === null) {
                throw new ValidationException(['project_id' => [t('finance.expense.error.project_unavailable')]]);
            }
            $this->assertAttachable($actingUser, $project);
        }
        $contractId = $this->requestedContract($input);
        $this->assertReferable($actingUser, $contractId);

        // Stage 5.2 (L1): the form's submission key is claimed first in the transaction; a
        // repeat of the same submission is answered with the expense the first one created.
        $key = SubmissionKeyRepository::keyFrom($input);
        try {
            return $this->expenses->atomically(function () use ($actingUser, $fields, $projectId, $contractId, $key): array {
                if ($key !== null && !$this->submissionKeys->claim(SubmissionKeyRepository::SCOPE_EXPENSE, $key, (int) $actingUser['id'])) {
                    throw new DuplicateSubmission();
                }
                $this->assertOpen((string) $fields['expense_date']);
                $id = $this->expenses->create($fields + [
                    'entry_type' => 'expense',
                    'project_id' => $projectId,
                    'contract_id' => $contractId,
                    'owner_id' => (int) $actingUser['id'],
                    'team_id' => $actingUser['team_id'] ?? null,
                    'department_id' => $actingUser['department_id'] ?? null,
                    'status' => 'recorded',
                    'created_by_user_id' => (int) $actingUser['id'],
                ]);
                if ($key !== null) {
                    $this->submissionKeys->attach(SubmissionKeyRepository::SCOPE_EXPENSE, $key, $id);
                }
                $expense = $this->expenses->findById($id);
                $this->timeline->record(self::SUBJECT, $id, 'created', 'Expense recorded: ' . $this->summary($expense), (int) $actingUser['id']);
                if ($contractId !== null) {
                    $this->timeline->record(self::SUBJECT, $id, 'contract_changed', 'Contract reference set (reference only)', (int) $actingUser['id']);
                }
                if ($projectId !== null) {
                    $this->timeline->record(self::PROJECT_TYPE, $projectId, 'expense_attributed', 'An expense was attributed to this project', (int) $actingUser['id']);
                }

                return $expense;
            });
        } catch (DuplicateSubmission) {
            return $this->find($actingUser, $this->originalOf(SubmissionKeyRepository::SCOPE_EXPENSE, (string) $key, $actingUser));
        }
    }

    /** The record a repeated submission refers to (same user, same organization), else a validation error. */
    private function originalOf(string $scope, string $key, array $actingUser): int
    {
        return $this->submissionKeys->original($scope, $key, (int) $actingUser['id'])
            ?? throw new ValidationException(['submission_key' => [t('finance.error.already_submitted')]]);
    }

    /**
     * Edit an ORIGINAL expense (corrections are never edited). Project:
     * input 'remove_project' = 1 removes it; a different 'project_id' changes
     * it (E7: projects.edit on both); otherwise it stays.
     */
    public function update(array $actingUser, int $id, array $input): array
    {
        $expense = $this->manageable($actingUser, $id);
        if ($expense['entry_type'] !== 'expense') {
            throw new ValidationException(['expense' => [t('finance.expense.error.correction_not_editable')]]);
        }
        $fields = $this->validated($input, $expense);
        $oldProject = $expense['project_id'] !== null ? (int) $expense['project_id'] : null;
        $newProject = !empty($input['remove_project']) ? null : ($this->requestedProject($input) ?? $oldProject);
        if ($newProject !== $oldProject) {
            $this->authorizeProjectSide($actingUser, $oldProject);
            if ($newProject !== null) {
                $project = $this->project($actingUser, $newProject);
                if ($project === null) {
                    throw new ValidationException(['project_id' => [t('finance.expense.error.project_unavailable')]]);
                }
                $this->assertAttachable($actingUser, $project);
            }
        }
        // Contract reference (Stage 5.1): 'remove_contract' = 1 clears it, a different
        // 'contract_id' replaces it (must be viewable), otherwise it stays.
        $oldContract = ($expense['contract_id'] ?? null) !== null ? (int) $expense['contract_id'] : null;
        $newContract = !empty($input['remove_contract']) ? null : ($this->requestedContract($input) ?? $oldContract);
        if ($newContract !== $oldContract) {
            $this->assertReferable($actingUser, $newContract);
        }

        return $this->expenses->atomically(function () use ($actingUser, $id, $fields, $oldProject, $newProject, $oldContract, $newContract): array {
            $locked = $this->expenses->lockForUpdate($id);
            if ($locked === null || $locked['status'] !== 'recorded') {
                throw new ValidationException(['expense' => [t('finance.expense.error.not_recorded')]]);
            }
            $this->assertOpen((string) $locked['expense_date']);
            $this->assertOpen((string) $fields['expense_date']);

            $recorded = array_values(array_filter($this->expenses->correctionsOf($id), fn (array $c) => $c['status'] === 'recorded'));
            if ($recorded !== []) {
                // The original's currency / project / category are frozen while it has recorded corrections (E8).
                if ((int) $fields['currency_id'] !== (int) $locked['currency_id'] || $fields['category'] !== $locked['category'] || $newProject !== ($locked['project_id'] !== null ? (int) $locked['project_id'] : null)
                    || $newContract !== (($locked['contract_id'] ?? null) !== null ? (int) $locked['contract_id'] : null)) {
                    throw new ValidationException(['expense' => [t('finance.expense.error.frozen_by_corrections')]]);
                }
                $this->assertNonNegative(['amount' => $fields['amount']] + $locked, $recorded);
            }

            $this->expenses->updateExpense($id, $fields + ['project_id' => $newProject, 'contract_id' => $newContract, 'updated_by_user_id' => (int) $actingUser['id']]);
            $after = $this->expenses->findById($id);
            $this->timeline->record(self::SUBJECT, $id, 'updated', 'Expense updated: ' . $this->summary($after), (int) $actingUser['id']);
            if ($newContract !== $oldContract) {
                $this->timeline->record(self::SUBJECT, $id, 'contract_changed', $newContract === null ? 'Contract reference removed' : 'Contract reference changed (reference only)', (int) $actingUser['id']);
            }
            if ($newProject !== $oldProject) {
                $this->timeline->record(self::SUBJECT, $id, 'project_changed', 'Project changed', (int) $actingUser['id']);
                if ($oldProject !== null && $this->projectExists($oldProject)) {
                    $this->timeline->record(self::PROJECT_TYPE, $oldProject, 'expense_detached', 'An expense attribution was removed', (int) $actingUser['id']);
                }
                if ($newProject !== null) {
                    $this->timeline->record(self::PROJECT_TYPE, $newProject, 'expense_attributed', 'An expense was attributed to this project', (int) $actingUser['id']);
                }
            }

            return $after;
        });
    }

    /** Void an original (no recorded corrections) or a correction (D-P2, invariant re-checked). */
    public function void(array $actingUser, int $id, string $reason): array
    {
        $expense = $id > 0 ? $this->expenses->findById($id) : null;
        if ($expense === null) {
            throw new NotFoundException('Expense not found.');
        }
        $isCorrection = $expense['entry_type'] === 'correction';
        if ($isCorrection) {
            $original = $this->expenses->findById((int) $expense['correction_of_id']);
            if ($original === null) {
                throw new NotFoundException('Expense not found.');
            }
            $this->authorizeCorrection($actingUser, $original);
        } elseif (!$this->authorization->can($actingUser, self::MANAGE, $expense)) {
            throw new AuthorizationException();
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => [t('finance.expense.error.reason_required')]]);
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new ValidationException(['reason' => [t('finance.expense.error.reason_too_long')]]);
        }

        return $this->expenses->atomically(function () use ($actingUser, $id, $expense, $isCorrection, $reason): array {
            // Lock order: the original first (as creating a correction does), then the row itself.
            $original = $isCorrection ? $this->expenses->lockForUpdate((int) $expense['correction_of_id']) : null;
            $locked = $this->expenses->lockForUpdate($id);
            if ($locked === null || $locked['status'] !== 'recorded') {
                throw new ValidationException(['expense' => [t('finance.expense.error.not_recorded')]]);
            }
            $this->assertOpen((string) $locked['expense_date']);
            if ($isCorrection) {
                $remaining = array_values(array_filter(
                    $this->expenses->correctionsOf((int) $original['id']),
                    fn (array $c) => $c['status'] === 'recorded' && (int) $c['id'] !== $id
                ));
                $this->assertNonNegative($original, $remaining);
            } elseif (array_filter($this->expenses->correctionsOf($id), fn (array $c) => $c['status'] === 'recorded') !== []) {
                throw new ValidationException(['expense' => [t('finance.expense.error.has_corrections')]]);
            }

            $this->expenses->updateExpense($id, [
                'status' => 'void', 'voided_at' => date('Y-m-d H:i:s'), 'voided_by_user_id' => (int) $actingUser['id'],
                'void_reason' => $reason, 'updated_by_user_id' => (int) $actingUser['id'],
            ]);
            $this->timeline->record(self::SUBJECT, $id, 'voided', ($isCorrection ? 'Correction voided: ' : 'Expense voided: ') . $reason, (int) $actingUser['id']);
            if ($isCorrection) {
                $this->timeline->record(self::SUBJECT, (int) $original['id'], 'correction_voided', 'Correction #' . $id . ' voided', (int) $actingUser['id']);
            }
            if ($locked['project_id'] !== null && $this->projectExists((int) $locked['project_id'])) {
                $this->timeline->record(self::PROJECT_TYPE, (int) $locked['project_id'], $isCorrection ? 'expense_corrected' : 'expense_voided',
                    $isCorrection ? 'An expense correction of this project was voided' : 'An expense of this project was voided', (int) $actingUser['id']);
            }

            return $this->expenses->findById($id);
        });
    }

    /**
     * A correction of an original whose period is CLOSED (E8, D-P2): its own
     * date in an open period, increase / decrease of a positive amount, never
     * taking the original's net below zero. Locks the original first.
     */
    public function createCorrection(array $actingUser, int $originalId, array $input): array
    {
        $original = $originalId > 0 ? $this->expenses->findById($originalId) : null;
        if ($original === null) {
            throw new NotFoundException('Expense not found.');
        }
        $this->authorizeCorrection($actingUser, $original);
        $direction = (string) ($input['correction_direction'] ?? '');
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new ValidationException(['correction_direction' => [t('finance.expense.error.invalid_direction')]]);
        }
        // Category and currency always come from the original, never from the form.
        $fields = $this->validated(['category' => $original['category'], 'currency_id' => (string) $original['currency_id']] + $input, null, true);

        return $this->expenses->atomically(function () use ($actingUser, $originalId, $direction, $fields): array {
            $locked = $this->expenses->lockForUpdate($originalId);
            if ($locked === null || $locked['entry_type'] !== 'expense') {
                throw new ValidationException(['expense' => [t('finance.expense.error.not_an_original')]]);
            }
            if ($locked['status'] !== 'recorded') {
                throw new ValidationException(['expense' => [t('finance.expense.error.not_recorded')]]);
            }
            if (!$this->expenses->isDateLockedForUpdate((string) $locked['expense_date'])) {
                throw new ValidationException(['expense' => [t('finance.expense.error.original_period_open')]]);
            }
            $this->assertOpen((string) $fields['expense_date']);
            $recorded = array_values(array_filter($this->expenses->correctionsOf($originalId), fn (array $c) => $c['status'] === 'recorded'));
            $candidate = ['amount' => $fields['amount'], 'entry_type' => 'correction', 'correction_direction' => $direction, 'status' => 'recorded'];
            $this->assertNonNegative($locked, [...$recorded, $candidate]);

            $id = $this->expenses->create([
                'entry_type' => 'correction',
                'correction_of_id' => $originalId,
                'correction_direction' => $direction,
                'category' => $locked['category'],
                'currency_id' => (int) $locked['currency_id'],
                'project_id' => $locked['project_id'],
                'contract_id' => $locked['contract_id'] ?? null,
                'owner_id' => (int) $locked['owner_id'],
                'team_id' => $locked['team_id'],
                'department_id' => $locked['department_id'],
                'description' => $fields['description'],
                'reference_number' => $fields['reference_number'],
                'expense_date' => $fields['expense_date'],
                'amount' => $fields['amount'],
                'payee_contact_id' => $fields['payee_contact_id'],
                'payee_company_id' => $fields['payee_company_id'],
                'status' => 'recorded',
                'created_by_user_id' => (int) $actingUser['id'],
            ]);
            $correction = $this->expenses->findById($id);
            $this->timeline->record(self::SUBJECT, $id, 'created', 'Correction of expense #' . $originalId . ' recorded: ' . $direction . ' ' . $this->summary($correction), (int) $actingUser['id']);
            $this->timeline->record(self::SUBJECT, $originalId, 'corrected', 'Correction #' . $id . ' recorded (' . $direction . ')', (int) $actingUser['id']);
            if ($locked['project_id'] !== null && $this->projectExists((int) $locked['project_id'])) {
                $this->timeline->record(self::PROJECT_TYPE, (int) $locked['project_id'], 'expense_corrected', 'An expense of this project was corrected', (int) $actingUser['id']);
            }

            return $correction;
        });
    }

    // ---- Receipts (E6) -------------------------------------------------------

    public function uploadReceipt(array $actingUser, int $id, ?array $file): void
    {
        $expense = $this->manageable($actingUser, $id);
        if ($file === null) {
            throw new ValidationException(['file' => [t('finance.expense.error.file_required')]]);
        }
        $this->expenses->atomically(function () use ($actingUser, $id, $expense, $file): void {
            $this->lockedForReceipts($id);
            // AttachmentService records "Attached …" on the expense's own timeline.
            $this->attachments->upload(self::SUBJECT, $id, (int) $expense['organization_id'], (int) $actingUser['id'], $file);
        });
    }

    public function deleteReceipt(array $actingUser, int $id, int $attachmentId): void
    {
        $this->manageable($actingUser, $id);
        $this->expenses->atomically(function () use ($actingUser, $id, $attachmentId): void {
            $this->lockedForReceipts($id);
            $attachment = $this->attachments->findForSubject(self::SUBJECT, $id, $attachmentId);
            if ($attachment === null) {
                throw new NotFoundException();
            }
            $this->attachments->deleteFromSubject(self::SUBJECT, $id, $attachmentId);
            $this->timeline->record(self::SUBJECT, $id, 'receipt_removed', 'Receipt removed: ' . (string) $attachment['filename'], (int) $actingUser['id']);
        });
    }

    /** @return array{0: array, 1: string} the attachment row and its file path */
    public function receipt(array $actingUser, int $id, int $attachmentId): array
    {
        $this->find($actingUser, $id);
        $attachment = $this->attachments->findForSubject(self::SUBJECT, $id, $attachmentId);
        if ($attachment === null) {
            throw new NotFoundException();
        }

        return [$attachment, $this->attachments->absolutePath($attachment)];
    }

    // ---- Internals -------------------------------------------------------------

    /** Signed effect of one row (E8): an ordinary expense or an increase → +amount; a decrease → −amount. */
    public function signed(array $row): string
    {
        $amount = (string) $row['amount'];

        return ($row['entry_type'] ?? 'expense') === 'correction' && ($row['correction_direction'] ?? '') === 'decrease'
            ? bcmul($amount, '-1', 18)
            : $amount;
    }

    /** Net of an original: its amount ± its RECORDED corrections (voided rows don't count). */
    public function net(array $original, array $corrections): string
    {
        $net = $original['status'] === 'recorded' ? (string) $original['amount'] : '0';
        foreach ($corrections as $c) {
            if (($c['status'] ?? 'recorded') === 'recorded') {
                $net = bcadd($net, $this->signed($c), 18);
            }
        }

        return $net;
    }

    private function assertNonNegative(array $original, array $corrections): void
    {
        if (bccomp($this->net(['status' => 'recorded'] + $original, $corrections), '0', 18) < 0) {
            throw new ValidationException(['amount' => [t('finance.expense.error.negative_net')]]);
        }
    }

    /** D-P3: a closed period covering $date (locking read, inside the caller's transaction) refuses the operation. */
    private function assertOpen(string $date): void
    {
        if ($this->expenses->isDateLockedForUpdate($date)) {
            throw new ValidationException(['expense_date' => [t('finance.expense.error.period_closed')]]);
        }
    }

    /** Non-locking read for page flags only (every action re-checks with the locking read). */
    private function isLocked(string $date): bool
    {
        return $this->expenses->isDateLocked($date);
    }

    private function lockedForReceipts(int $id): array
    {
        $locked = $this->expenses->lockForUpdate($id);
        if ($locked === null || $locked['status'] !== 'recorded') {
            throw new ValidationException(['file' => [t('finance.expense.error.not_recorded')]]);
        }
        $this->assertOpen((string) $locked['expense_date']);

        return $locked;
    }

    /** The expense, if this user may MANAGE it: 404 / 403 otherwise. */
    private function manageable(array $actingUser, int $id): array
    {
        $expense = $id > 0 ? $this->expenses->findById($id) : null;
        if ($expense === null) {
            throw new NotFoundException('Expense not found.');
        }
        if (!$this->authorization->can($actingUser, self::MANAGE, $expense)) {
            throw new AuthorizationException();
        }

        return $expense;
    }

    /** D-P2: manage + scope on the ORIGINAL, and projects.edit on its project when that project still exists. */
    private function authorizeCorrection(array $actingUser, array $original): void
    {
        if (!$this->authorization->can($actingUser, self::MANAGE, $original)) {
            throw new AuthorizationException();
        }
        $this->authorizeProjectSide($actingUser, $original['project_id'] !== null ? (int) $original['project_id'] : null);
    }

    private function canCorrect(array $actingUser, array $original): bool
    {
        try {
            $this->authorizeCorrection($actingUser, $original);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    /**
     * projects.edit on an existing project (record scope, through find()).
     * A project that no longer exists needs nothing; one that exists but the
     * user can't see, or Projects not registered at all → 403 (fails closed).
     */
    private function authorizeProjectSide(array $actingUser, ?int $projectId): void
    {
        if ($projectId === null || !$this->projectExists($projectId)) {
            return;
        }
        $project = $this->project($actingUser, $projectId);
        if ($project === null || !$this->authorization->can($actingUser, self::PROJECT_EDIT, $project)) {
            throw new AuthorizationException();
        }
    }

    /** E7 + E9 for a NEW attribution: projects.edit (403) and not cancelled (422). */
    private function assertAttachable(array $actingUser, array $project): void
    {
        if (!$this->authorization->can($actingUser, self::PROJECT_EDIT, $project)) {
            throw new AuthorizationException();
        }
        if (($project['status'] ?? '') === 'cancelled') {
            throw new ValidationException(['project_id' => [t('finance.expense.error.project_cancelled')]]);
        }
    }

    private function canAttachTo(array $actingUser, array $project): bool
    {
        return $this->authorization->can($actingUser, self::PROJECT_EDIT, $project) && ($project['status'] ?? '') !== 'cancelled';
    }

    private function project(array $actingUser, int $projectId): ?array
    {
        return $projectId > 0 ? $this->recordTypes?->find(self::PROJECT_TYPE, $projectId, $actingUser) : null;
    }

    /** Whether the project row still exists in this organization (the registry's own tenant-scoped finder). */
    private function projectExists(int $projectId): bool
    {
        $finder = $this->recordTypes?->get(self::PROJECT_TYPE)?->finder;

        return $finder === null || $finder($projectId) !== null;
    }

    private function requestedProject(array $input): ?int
    {
        $id = (int) ($input['project_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function requestedContract(array $input): ?int
    {
        $id = (int) ($input['contract_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** The contract row if this user may view it (Contracts available, contracts.view, organization, record scope), else null. */
    private function contract(array $actingUser, int $contractId): ?array
    {
        return $contractId > 0 ? $this->recordTypes?->find(self::CONTRACT_TYPE, $contractId, $actingUser) : null;
    }

    /** A contract reference may be set only to a contract this user can view (422 otherwise — never a project inference). */
    private function assertReferable(array $actingUser, ?int $contractId): void
    {
        if ($contractId !== null && $this->contract($actingUser, $contractId) === null) {
            throw new ValidationException(['contract_id' => [t('finance.expense.error.contract_unavailable')]]);
        }
    }

    private function contractLabel(array $contract): string
    {
        $number = trim((string) ($contract['contract_number'] ?? ''));

        return (string) ($contract['title'] ?? ('#' . $contract['id'])) . ($number !== '' ? ' (' . $number . ')' : '');
    }

    /**
     * Field validation shared by create / edit / correction (E3–E5). Category
     * and currency are fixed by the original for corrections ($forCorrection).
     *
     * @return array<string, mixed>
     */
    private function validated(array $input, ?array $current, bool $forCorrection = false): array
    {
        $errors = [];

        $category = (string) ($input['category'] ?? '');
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors['category'][] = t('finance.expense.error.invalid_category');
        }
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $errors['description'][] = t('finance.expense.error.invalid_description');
        }
        $reference = trim((string) ($input['reference_number'] ?? ''));
        if (mb_strlen($reference) > 100) {
            $errors['reference_number'][] = t('finance.expense.error.reference_too_long');
        }
        $date = trim((string) ($input['expense_date'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['expense_date'][] = t('finance.expense.error.invalid_date');
        }
        $amount = str_replace([',', ' '], '', trim((string) ($input['amount'] ?? '')));
        if (preg_match('/^\d{1,20}(\.\d{1,18})?$/', $amount) !== 1 || bccomp($amount, '0', 18) <= 0) {
            $errors['amount'][] = t('finance.expense.error.invalid_amount');
        }

        $currencyId = (int) ($input['currency_id'] ?? 0);
        if (!$forCorrection) {
            $unchanged = $current !== null && $currencyId === (int) $current['currency_id'];
            $currency = $currencyId > 0 ? $this->currencies->findById($currencyId) : null;
            if ($currency === null || (!$unchanged && empty($currency['is_active']))) {
                $errors['currency_id'][] = t('finance.error.invalid_currency');
            }
        }
        // Stage 5.2 (L2): at most the currency's money precision (IRR 3) — never truncated.
        if (!isset($errors['amount']) && $currencyId > 0 && Money::fractionDigits($amount) > Money::decimalsFor($currencyId)) {
            $errors['amount'][] = t('finance.error.amount_precision', ['max' => Money::decimalsFor($currencyId)]);
        }

        $contactId = (int) ($input['payee_contact_id'] ?? 0);
        $companyId = (int) ($input['payee_company_id'] ?? 0);
        if ($contactId > 0 && $companyId > 0) {
            $errors['payee_contact_id'][] = t('finance.expense.error.one_payee');
        } elseif ($contactId > 0 && $this->contacts->findById($contactId) === null) {
            $errors['payee_contact_id'][] = t('finance.error.invalid_contact');
        } elseif ($companyId > 0 && $this->companies->findById($companyId) === null) {
            $errors['payee_company_id'][] = t('finance.expense.error.invalid_company');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'category' => $category,
            'description' => $description,
            'reference_number' => $reference !== '' ? $reference : null,
            'expense_date' => $date,
            'amount' => $amount,
            'currency_id' => $currencyId,
            'payee_contact_id' => $contactId > 0 ? $contactId : null,
            'payee_company_id' => $companyId > 0 ? $companyId : null,
        ];
    }

    private function payeeLabel(array $expense): ?string
    {
        if ($expense['payee_contact_id'] !== null) {
            $c = $this->contacts->findById((int) $expense['payee_contact_id']);

            return $c !== null ? trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')) : null;
        }
        if ($expense['payee_company_id'] !== null) {
            return $this->companies->findById((int) $expense['payee_company_id'])['name'] ?? null;
        }

        return null;
    }

    private function projectLabel(array $project): string
    {
        $code = trim((string) ($project['code'] ?? ''));

        return (string) ($project['name'] ?? ('#' . $project['id'])) . ($code !== '' ? ' (' . $code . ')' : '');
    }

    /** Audit text for the EXPENSE timeline (never written to the project's). */
    private function summary(array $expense): string
    {
        $currency = $this->currencies->findById((int) $expense['currency_id']);
        $amount = str_contains((string) $expense['amount'], '.') ? rtrim(rtrim((string) $expense['amount'], '0'), '.') : (string) $expense['amount'];

        return $expense['category'] . ', ' . $amount . ' ' . ($currency['code'] ?? '') . ', ' . $expense['expense_date'];
    }
}
