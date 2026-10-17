<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use App\Application\Services\TimelineService;
use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\NumberSeriesRepository;
use App\Infrastructure\Records\RecordTypeRegistry;
use Modules\Contracts\ContractCategoryRepository;
use Modules\Contracts\ContractLinkRepository;
use Modules\Contracts\ContractSettingsRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Contracts\ContractPermission;
use Modules\Contracts\ContractRepository;
use Modules\Contracts\Events\ContractActivated;
use Modules\Contracts\Events\ContractTerminated;

/**
 * Every contract rule lives here; controllers (step 2.5) stay thin.
 * Uses Core only — contacts, companies, currencies, users, timeline,
 * number series, RBAC, EventBus. No other module is referenced.
 *
 * Lifecycle (docs/contracts-module-design.md):
 *   draft ──submit──▶ pending_approval ──approve──▶ active
 *     │  ◀──return_to_draft──┘                       │
 *     ├──activate────────────────────────────────────▶ active ──terminate──▶ terminated
 *     └──cancel (draft or pending) ──▶ cancelled      └── (end_date passed) = expired, computed on read
 *   renew (active or expired) ──▶ new draft, parent_contract_id = old, relation = renewal
 *
 * Errors are ValidationException with translation keys as messages
 * (the controller translates them), same as Internal Mail's services.
 */
final class ContractService
{
    public const STATUSES = ['draft', 'pending_approval', 'active', 'expired', 'terminated', 'cancelled'];
    private const EDITABLE = ['draft', 'pending_approval'];
    private const DELETABLE = ['draft', 'pending_approval', 'cancelled'];
    private const NUMBER_TYPE = 'contract';
    private const NUMBER_PREFIX = 'CTR-';

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractCategoryRepository $categories,
        private readonly NumberSeriesRepository $numbers,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly EventBus $events,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly CurrencyRepository $currencies,
        private readonly ContractLinkRepository $links = new ContractLinkRepository(),
        private readonly ?RecordTypeRegistry $recordTypes = null,
        private readonly ContractSettingsRepository $settings = new ContractSettingsRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    // ---- Reading ---------------------------------------------------------

    /** One page of contracts the user may see (record scope applied in SQL). */
    public function list(array $actingUser, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        $rows = $this->contracts->search($this->scoped($actingUser, $filters), $limit, $offset);

        return array_map(fn (array $row) => $this->decorate($row), $rows);
    }

    public function count(array $actingUser, array $filters = []): int
    {
        return $this->contracts->countSearch($this->scoped($actingUser, $filters));
    }

    public function find(array $actingUser, int $id): array
    {
        $contract = $this->require($id);
        $this->authorizeRecord($actingUser, ContractPermission::VIEW, $contract);

        return $this->decorate($contract);
    }

    /** `expired` is computed: an active contract whose end date has passed. */
    public function effectiveStatus(array $contract, ?string $today = null): string
    {
        $today ??= \App\Infrastructure\Time\OrganizationTimezone::shared()->today(); // the organization's date, not the server's

        if ($contract['status'] === 'active' && !empty($contract['end_date']) && $contract['end_date'] < $today) {
            return 'expired';
        }

        return (string) $contract['status'];
    }

    /** Page-level checks (e.g. show the "New contract" button); record rules still apply on the action itself. */
    public function userCan(array $actingUser, string $permission): bool
    {
        return in_array($permission, ContractPermission::ALL, true) && $this->authorization->can($actingUser, $permission);
    }

    /**
     * What this user may do with this contract right now (for the pages
     * in 2.5): permission AND current state.
     *
     * @return array<string, bool>
     */
    public function allowedActions(array $actingUser, array $contract): array
    {
        $status = $this->effectiveStatus($contract);
        $can = fn (string $key) => $this->authorization->can($actingUser, $key, $contract);

        return [
            'edit' => in_array($status, self::EDITABLE, true) && $can(ContractPermission::EDIT),
            'delete' => in_array($status, self::DELETABLE, true) && $can(ContractPermission::DELETE),
            'submit' => $status === 'draft' && $can(ContractPermission::EDIT),
            'return_to_draft' => $status === 'pending_approval' && $can(ContractPermission::APPROVE),
            'approve' => $status === 'pending_approval' && $can(ContractPermission::APPROVE),
            'activate' => in_array($status, self::EDITABLE, true) && $can(ContractPermission::ACTIVATE),
            'cancel' => in_array($status, self::EDITABLE, true) && $can(ContractPermission::EDIT),
            'terminate' => $status === 'active' && $can(ContractPermission::TERMINATE),
            'renew' => in_array($status, ['active', 'expired'], true) && $can(ContractPermission::RENEW),
            // Links are metadata, allowed in any status (step 2.7).
            'link' => $can(ContractPermission::EDIT),
        ];
    }

    // ---- Writing ---------------------------------------------------------

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, ContractPermission::CREATE)) {
            throw new AuthorizationException();
        }

        $fields = $this->validated($input);
        $fields += [
            'owner_id' => (int) $actingUser['id'],
            'team_id' => $actingUser['team_id'] ?? null,
            'department_id' => $actingUser['department_id'] ?? null,
            'status' => 'draft',
            'relation' => 'original',
            'created_by_user_id' => (int) $actingUser['id'],
        ];

        $id = $this->contracts->create($fields);
        $this->timeline->record('contract', $id, 'created', 'Contract created', (int) $actingUser['id']);

        return $this->decorate($this->require($id));
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $contract = $this->require($id);
        $this->authorizeRecord($actingUser, ContractPermission::EDIT, $contract);
        $this->assertStatus($contract, self::EDITABLE, 'contracts.error.not_editable');

        $this->contracts->updateContract($id, $this->validated($input));
        $this->timeline->record('contract', $id, 'updated', 'Contract details updated', (int) $actingUser['id']);

        return $this->decorate($this->require($id));
    }

    public function delete(array $actingUser, int $id): void
    {
        $contract = $this->require($id);
        $this->authorizeRecord($actingUser, ContractPermission::DELETE, $contract);
        $this->assertStatus($contract, self::DELETABLE, 'contracts.error.not_deletable');

        $this->contracts->deleteContract($id);
    }

    public function submit(array $actingUser, int $id): array
    {
        return $this->transition($actingUser, $id, ContractPermission::EDIT, ['draft'], ['status' => 'pending_approval'], 'submitted', 'Submitted for approval');
    }

    public function returnToDraft(array $actingUser, int $id): array
    {
        return $this->transition($actingUser, $id, ContractPermission::APPROVE, ['pending_approval'], ['status' => 'draft'], 'returned_to_draft', 'Returned to draft');
    }

    public function cancel(array $actingUser, int $id): array
    {
        return $this->transition($actingUser, $id, ContractPermission::EDIT, self::EDITABLE, ['status' => 'cancelled'], 'cancelled', 'Contract cancelled');
    }

    /** Approver activates a pending contract. */
    public function approve(array $actingUser, int $id, ?string $signedOn = null): array
    {
        return $this->makeActive($actingUser, $id, ContractPermission::APPROVE, ['pending_approval'], $signedOn);
    }

    /** Direct activation from draft or pending (needs contracts.activate). */
    public function activate(array $actingUser, int $id, ?string $signedOn = null): array
    {
        return $this->makeActive($actingUser, $id, ContractPermission::ACTIVATE, self::EDITABLE, $signedOn);
    }

    public function terminate(array $actingUser, int $id, string $reason = ''): array
    {
        $contract = $this->require($id);
        $this->authorizeRecord($actingUser, ContractPermission::TERMINATE, $contract);

        if ($this->effectiveStatus($contract) !== 'active') {
            throw new ValidationException(['status' => ['contracts.error.not_active']]);
        }

        $this->contracts->updateContract($id, ['status' => 'terminated', 'terminated_at' => date('Y-m-d H:i:s')]);
        $reason = trim($reason);
        $this->timeline->record('contract', $id, 'terminated', 'Contract terminated' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 500) : ''), (int) $actingUser['id']);
        $this->events->dispatch(new ContractTerminated((int) $contract['organization_id'], $id, (int) $actingUser['id']));

        return $this->decorate($this->require($id));
    }

    /**
     * New draft continuing an active or expired contract. Dates follow
     * on: start = day after the old end; same length when both old dates
     * are known. Owner = the person renewing.
     */
    public function renew(array $actingUser, int $id): array
    {
        $old = $this->require($id);
        $this->authorizeRecord($actingUser, ContractPermission::RENEW, $old);

        if (!in_array($this->effectiveStatus($old), ['active', 'expired'], true)) {
            throw new ValidationException(['status' => ['contracts.error.not_renewable']]);
        }

        $start = $end = null;
        if (!empty($old['end_date'])) {
            $start = date('Y-m-d', strtotime($old['end_date'] . ' +1 day'));
            if (!empty($old['start_date'])) {
                $lengthDays = (int) round((strtotime($old['end_date']) - strtotime($old['start_date'])) / 86400);
                $end = date('Y-m-d', strtotime($start . ' +' . $lengthDays . ' days'));
            }
        }

        $newId = $this->contracts->create([
            'title' => $old['title'],
            'category_id' => $old['category_id'],
            'contact_id' => $old['contact_id'],
            'company_id' => $old['company_id'],
            'value' => $old['value'],
            'currency_id' => $old['currency_id'],
            'auto_renew' => $old['auto_renew'],
            'renewal_notice_days' => $old['renewal_notice_days'],
            'description' => $old['description'],
            'terms' => $old['terms'],
            'start_date' => $start,
            'end_date' => $end,
            'parent_contract_id' => $id,
            'relation' => 'renewal',
            'status' => 'draft',
            'owner_id' => (int) $actingUser['id'],
            'team_id' => $actingUser['team_id'] ?? null,
            'department_id' => $actingUser['department_id'] ?? null,
            'created_by_user_id' => (int) $actingUser['id'],
        ]);

        $this->timeline->record('contract', $newId, 'created', 'Created as renewal of ' . ($old['contract_number'] ?? ('#' . $id)), (int) $actingUser['id']);
        $this->timeline->record('contract', $id, 'renewed', 'Renewal draft created', (int) $actingUser['id']);

        return $this->decorate($this->require($newId));
    }

    // ---- Links to other modules' records (step 2.7) -----------------------
    //
    // A link points at a RecordTypeRegistry type (e.g. Sales' `deal`) and is
    // validated through RecordTypeRegistry::find(): the owning module must be
    // available to the user, who must be allowed to view that very record in
    // their own organization. Contracts never imports the other module.

    /** Links a record to a contract (idempotent). Allowed in any status: it is metadata, not contract content. */
    public function link(array $actingUser, int $contractId, string $type, int $recordId): array
    {
        $contract = $this->require($contractId);
        $this->authorizeRecord($actingUser, ContractPermission::EDIT, $contract);

        if ($type === 'contract' || $this->recordTypes === null) {
            throw new ValidationException(['link' => ['contracts.error.link_target_invalid']]);
        }

        $target = $this->recordTypes->find($type, $recordId, $actingUser);
        if ($target === null) {
            throw new ValidationException(['link' => ['contracts.error.link_target_invalid']]);
        }

        $existing = $this->links->find($contractId, $type, $recordId);
        if ($existing !== null) {
            return $existing;
        }

        $label = $this->recordLabel($target, $type, $recordId);
        $this->links->create($contractId, $type, $recordId, $label, (int) $actingUser['id']);
        $this->timeline->record('contract', $contractId, 'linked', 'Linked to ' . $type . ' "' . $label . '"', (int) $actingUser['id']);

        return $this->links->find($contractId, $type, $recordId);
    }

    public function unlink(array $actingUser, int $contractId, int $linkId): void
    {
        $contract = $this->require($contractId);
        $this->authorizeRecord($actingUser, ContractPermission::EDIT, $contract);

        foreach ($this->links->forContract($contractId) as $link) {
            if ((int) $link['id'] === $linkId) {
                $this->links->deleteLink($linkId);
                $this->timeline->record('contract', $contractId, 'unlinked', 'Unlinked ' . $link['linked_type'] . ' "' . $link['linked_label'] . '"', (int) $actingUser['id']);

                return;
            }
        }

        throw new NotFoundException('Link not found.');
    }

    /**
     * A contract's links for display. `url` is set only when the target's
     * module is available and the user may view that record; otherwise the
     * stored label is shown without a link (module disabled, record gone…).
     *
     * @return array<int, array{id: int, type: string, record_id: int, label: string, label_key: ?string, url: ?string}>
     */
    public function links(array $actingUser, int $contractId): array
    {
        $out = [];
        foreach ($this->links->forContract($contractId) as $link) {
            $type = $this->recordTypes?->get($link['linked_type']);
            $live = $this->recordTypes?->find($link['linked_type'], (int) $link['linked_id'], $actingUser);
            $out[] = [
                'id' => (int) $link['id'],
                'type' => $link['linked_type'],
                'record_id' => (int) $link['linked_id'],
                'label' => $live !== null ? $this->recordLabel($live, $link['linked_type'], (int) $link['linked_id']) : $link['linked_label'],
                'label_key' => $type?->labelKey,
                'url' => $live !== null ? $type->showUrl((int) $link['linked_id']) : null,
            ];
        }

        return $out;
    }

    /** Contracts linked to one record of another module, within the user's view scope. */
    public function linkedTo(array $actingUser, string $type, int $recordId, ?int $limit = null): array
    {
        $ids = $this->links->contractIdsLinkedTo($type, $recordId);

        return $ids === [] ? [] : $this->list($actingUser, ['id_in' => $ids], $limit);
    }

    public function countLinkedTo(array $actingUser, string $type, int $recordId): int
    {
        $ids = $this->links->contractIdsLinkedTo($type, $recordId);

        return $ids === [] ? 0 : $this->count($actingUser, ['id_in' => $ids]);
    }

    // ---- Create from a deal (step 2.8) ------------------------------------

    /**
     * Form prefill for "Create contract" on a deal page, or null when the
     * deal isn't available to this user (Sales off, no deals.view, another
     * organization, outside record scope). Reads the deal only through
     * RecordTypeRegistry::find() — never Sales code. A field the deal
     * doesn't carry (or that no longer matches Core data) is left empty;
     * create() validates everything again anyway.
     *
     * @return array<string, string>|null
     */
    public function draftFromDeal(array $actingUser, int $dealId): ?array
    {
        $deal = $this->recordTypes?->find('deal', $dealId, $actingUser);
        if ($deal === null) {
            return null;
        }

        $draft = [
            'title' => (string) ($deal['name'] ?? ''),
            'contact_id' => !empty($deal['contact_id']) ? (string) $deal['contact_id'] : '',
            'company_id' => !empty($deal['company_id']) ? (string) $deal['company_id'] : '',
            'value' => '',
            'currency_id' => '',
            'source_type' => 'deal',
            'source_id' => (string) $dealId,
            'source_label' => (string) ($deal['name'] ?? ('deal #' . $dealId)),
            // The page URL is Sales' own (its RecordType), never hard-coded here.
            'source_url' => $this->recordTypes->get('deal')->showUrl($dealId),
        ];

        // Deals store a 3-letter code (not a currency_id): map it to an
        // active Core currency; amount only when there is one and it isn't 0.
        $currency = !empty($deal['currency_code']) ? $this->currencies->findByCode((string) $deal['currency_code']) : null;
        if ($currency !== null && !empty($currency['is_active'])) {
            $draft['currency_id'] = (string) $currency['id'];
            if (isset($deal['value']) && (float) $deal['value'] > 0) {
                $draft['value'] = rtrim(rtrim((string) $deal['value'], '0'), '.');
            }
        }

        return $draft;
    }

    /**
     * create() + link to the source record, atomically. The source is
     * re-checked here (hidden form fields are never trusted): if the user
     * can't see that record, nothing is created. Linking is part of
     * creating — it needs contracts.create, not contracts.edit.
     */
    public function createLinked(array $actingUser, array $input, string $sourceType, int $sourceId): array
    {
        if ($sourceType === 'contract' || $this->recordTypes === null) {
            throw new ValidationException(['source' => ['contracts.error.link_target_invalid']]);
        }

        $target = $this->recordTypes->find($sourceType, $sourceId, $actingUser);
        if ($target === null) {
            throw new ValidationException(['source' => ['contracts.error.link_target_invalid']]);
        }

        return $this->contracts->atomically(function () use ($actingUser, $input, $sourceType, $sourceId, $target): array {
            $contract = $this->create($actingUser, $input);
            $label = $this->recordLabel($target, $sourceType, $sourceId);
            $this->links->create((int) $contract['id'], $sourceType, $sourceId, $label, (int) $actingUser['id']);
            $this->timeline->record('contract', (int) $contract['id'], 'linked', 'Created from ' . $sourceType . ' "' . $label . '"', (int) $actingUser['id']);

            return $contract;
        });
    }

    // ---- Organization settings + auto-create (step 2.9) --------------------

    /** @return array{auto_create_on_deal_won: int} */
    public function settings(array $actingUser): array
    {
        $this->authorizeSettings($actingUser);

        return $this->settings->current();
    }

    public function saveSettings(array $actingUser, array $input): void
    {
        $this->authorizeSettings($actingUser);
        $this->settings->save(['auto_create_on_deal_won' => !empty($input['auto_create_on_deal_won']) ? 1 : 0], (int) $actingUser['id']);
    }

    /**
     * Called by DealStageChangedListener for a WON deal, inside the event's
     * organization (OrganizationContext::runAs). A system reaction — like
     * the Followups rule engine, it needs no contracts.create from anyone —
     * but it only runs when the organization switched it on, creates at
     * most ONE contract per deal (none if any contract is already linked),
     * and reads the deal through RecordTypeRegistry as the person who won
     * it (so Sales availability and their deals.view still apply).
     *
     * @return int|null the new draft's id, or null when nothing was created
     */
    public function autoCreateFromWonDeal(int $dealId, ?int $actorUserId): ?int
    {
        if (empty($this->settings->current()['auto_create_on_deal_won']) || $actorUserId === null || $this->recordTypes === null) {
            return null;
        }

        if ($this->links->contractIdsLinkedTo('deal', $dealId) !== []) {
            return null; // one contract per deal: never a duplicate on a second "won"
        }

        $actor = $this->activeOrgUser($actorUserId);
        $deal = $actor !== null ? $this->recordTypes->find('deal', $dealId, $actor) : null;
        $draft = $actor !== null ? $this->draftFromDeal($actor, $dealId) : null;
        if ($actor === null || $deal === null || $draft === null) {
            return null;
        }

        try {
            $fields = $this->validated($draft);
        } catch (ValidationException) {
            $fields = $this->validated(['title' => $draft['title'] !== '' ? $draft['title'] : 'Deal #' . $dealId]);
        }

        $owner = !empty($deal['owner_id']) ? ($this->activeOrgUser((int) $deal['owner_id']) ?? $actor) : $actor;
        $fields += [
            'owner_id' => (int) $owner['id'],
            'team_id' => $owner['team_id'] ?? null,
            'department_id' => $owner['department_id'] ?? null,
            'status' => 'draft',
            'relation' => 'original',
            'created_by_user_id' => (int) $actor['id'],
        ];

        $label = $this->recordLabel($deal, 'deal', $dealId);

        return $this->contracts->atomically(function () use ($fields, $dealId, $label, $actor): int {
            $id = $this->contracts->create($fields);
            $this->links->create($id, 'deal', $dealId, $label, (int) $actor['id']);
            $this->timeline->record('contract', $id, 'created', 'Created automatically when deal "' . $label . '" was won', (int) $actor['id']);

            return $id;
        });
    }

    /** A user of the current organization who is active, else null. */
    private function activeOrgUser(int $userId): ?array
    {
        $user = $this->users->findById($userId);

        return $user !== null && ($user['status'] ?? '') === 'active' && (int) $user['organization_id'] === OrganizationContext::id()
            ? $user
            : null;
    }

    /** A readable name for another module's record, without knowing its schema in advance. */
    private function recordLabel(array $record, string $type, int $id): string
    {
        foreach (['name', 'title', 'subject'] as $field) {
            if (!empty($record[$field])) {
                return (string) $record[$field];
            }
        }
        $person = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));

        return $person !== '' ? $person : $type . ' #' . $id;
    }

    // ---- Categories (contracts.manage_settings to change) ------------------

    public function categories(array $actingUser): array
    {
        if (!$this->authorization->can($actingUser, ContractPermission::VIEW)) {
            throw new AuthorizationException();
        }

        return $this->categories->all();
    }

    public function createCategory(array $actingUser, string $name): array
    {
        $this->authorizeSettings($actingUser);
        $name = $this->validCategoryName($name);

        return $this->categories->findById($this->categories->create($name));
    }

    public function renameCategory(array $actingUser, int $id, string $name): void
    {
        $this->authorizeSettings($actingUser);
        $this->requireCategory($id);
        $name = $this->validCategoryName($name, $id);
        $this->categories->rename($id, $name);
    }

    /** Contracts in the category keep existing; their category becomes empty (FK ON DELETE SET NULL). */
    public function deleteCategory(array $actingUser, int $id): void
    {
        $this->authorizeSettings($actingUser);
        $this->requireCategory($id);
        $this->categories->deleteCategory($id);
    }

    // ---- Internals ---------------------------------------------------------

    private function makeActive(array $actingUser, int $id, string $permission, array $fromStatuses, ?string $signedOn): array
    {
        $contract = $this->require($id);
        $this->authorizeRecord($actingUser, $permission, $contract);
        $this->assertStatus($contract, $fromStatuses, 'contracts.error.not_activatable');

        if (empty($contract['start_date'])) {
            throw new ValidationException(['start_date' => ['contracts.error.start_date_required_to_activate']]);
        }

        // signed_at is a local value (a typed date + 00:00:00); without one it is the organization's local now.
        $signedAt = \App\Infrastructure\Time\OrganizationTimezone::shared()->localNow();
        if ($signedOn !== null && trim($signedOn) !== '') {
            $signedAt = $this->validDate($signedOn, 'signed_at') . ' 00:00:00';
        }

        // Number + status change commit together; a failure consumes no number.
        $number = $this->contracts->atomically(function () use ($id, $contract, $signedAt): string {
            $number = $contract['contract_number'] ?: $this->numbers->next(self::NUMBER_TYPE, self::NUMBER_PREFIX);
            $this->contracts->updateContract($id, ['status' => 'active', 'contract_number' => $number, 'signed_at' => $signedAt]);

            return $number;
        });

        $this->timeline->record('contract', $id, 'activated', 'Contract activated as ' . $number, (int) $actingUser['id']);
        $this->events->dispatch(new ContractActivated((int) $contract['organization_id'], $id, $number, (int) $actingUser['id']));

        return $this->decorate($this->require($id));
    }

    private function transition(array $actingUser, int $id, string $permission, array $from, array $fields, string $event, string $text): array
    {
        $contract = $this->require($id);
        $this->authorizeRecord($actingUser, $permission, $contract);
        $this->assertStatus($contract, $from, 'contracts.error.invalid_transition');

        $this->contracts->updateContract($id, $fields);
        $this->timeline->record('contract', $id, $event, $text, (int) $actingUser['id']);

        return $this->decorate($this->require($id));
    }

    /**
     * Filters + the user's view scope. OWN/TEAM/DEPARTMENT become SQL
     * filters mirroring AuthorizationEngine::can() on a single record, so
     * the list never shows a row find() would refuse.
     */
    private function scoped(array $actingUser, array $filters): array
    {
        $scope = $this->authorization->scopeFor($actingUser, ContractPermission::VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        return match ($scope) {
            Scope::OWN => array_merge($filters, ['owner_id' => (int) $actingUser['id']]),
            Scope::TEAM => array_merge($filters, ['team_id' => $actingUser['team_id'] ?? -1]),
            Scope::DEPARTMENT => array_merge($filters, ['department_id' => $actingUser['department_id'] ?? -1]),
            default => $filters,
        };
    }

    private function decorate(array $contract): array
    {
        $contract['effective_status'] = $this->effectiveStatus($contract);

        return $contract;
    }

    /** @return array<string, mixed> columns from user input; throws ValidationException with translation keys */
    private function validated(array $input): array
    {
        $errors = [];
        $text = fn (string $key) => isset($input[$key]) ? trim((string) $input[$key]) : '';
        $intOrNull = fn (string $key) => $text($key) === '' ? null : (int) $text($key);

        $title = $text('title');
        if ($title === '') {
            $errors['title'][] = 'contracts.error.title_required';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'][] = 'contracts.error.title_too_long';
        }

        $categoryId = $intOrNull('category_id');
        if ($categoryId !== null && $this->categories->findById($categoryId) === null) {
            $errors['category_id'][] = 'contracts.error.invalid_category';
        }

        // Core contacts/companies, tenant-scoped: another org's id is "not found".
        $contactId = $intOrNull('contact_id');
        if ($contactId !== null && $this->contacts->findById($contactId) === null) {
            $errors['contact_id'][] = 'contracts.error.invalid_contact';
        }
        $companyId = $intOrNull('company_id');
        if ($companyId !== null && $this->companies->findById($companyId) === null) {
            $errors['company_id'][] = 'contracts.error.invalid_company';
        }

        $dates = [];
        foreach (['start_date', 'end_date'] as $key) {
            $dates[$key] = null;
            if ($text($key) !== '') {
                if ($this->isIsoDate($text($key))) {
                    $dates[$key] = $text($key);
                } else {
                    $errors[$key][] = 'contracts.error.invalid_date';
                }
            }
        }
        [$start, $end] = [$dates['start_date'], $dates['end_date']];
        if ($start !== null && $end !== null && $end < $start) {
            $errors['end_date'][] = 'contracts.error.end_before_start';
        }

        $value = str_replace([',', ' '], '', $text('value'));
        $currencyId = $intOrNull('currency_id');
        if ($value !== '') {
            if (preg_match('/^\d{1,20}(\.\d{1,18})?$/', $value) !== 1) {
                $errors['value'][] = 'contracts.error.invalid_value';
            }
            if ($currencyId === null) {
                $errors['currency_id'][] = 'contracts.error.currency_required';
            }
        }
        if ($currencyId !== null) {
            $currency = $this->currencies->findById($currencyId);
            if ($currency === null || empty($currency['is_active'])) {
                $errors['currency_id'][] = 'contracts.error.invalid_currency';
            }
        }

        $notice = $intOrNull('renewal_notice_days');
        if ($notice !== null && ($notice < 0 || $notice > 3650)) {
            $errors['renewal_notice_days'][] = 'contracts.error.invalid_notice_days';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'category_id' => $categoryId,
            'contact_id' => $contactId,
            'company_id' => $companyId,
            'start_date' => $start,
            'end_date' => $end,
            'value' => $value === '' ? null : $value,
            'currency_id' => $currencyId,
            'auto_renew' => !empty($input['auto_renew']) ? 1 : 0,
            'renewal_notice_days' => $notice,
            'description' => $text('description') === '' ? null : $text('description'),
            'terms' => $text('terms') === '' ? null : $text('terms'),
        ];
    }

    private function isIsoDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function validDate(string $value, string $field): string
    {
        $value = trim($value);
        if (!$this->isIsoDate($value)) {
            throw new ValidationException([$field => ['contracts.error.invalid_date']]);
        }

        return $value;
    }

    private function validCategoryName(string $name, ?int $exceptId = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new ValidationException(['name' => ['contracts.error.category_name_invalid']]);
        }
        $existing = $this->categories->findByName($name);
        if ($existing !== null && (int) $existing['id'] !== $exceptId) {
            throw new ValidationException(['name' => ['contracts.error.category_name_taken']]);
        }

        return $name;
    }

    private function assertStatus(array $contract, array $allowed, string $errorKey): void
    {
        if (!in_array($this->effectiveStatus($contract), $allowed, true)) {
            throw new ValidationException(['status' => [$errorKey]]);
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function authorizeSettings(array $actingUser): void
    {
        if (!$this->authorization->can($actingUser, ContractPermission::MANAGE_SETTINGS)) {
            throw new AuthorizationException();
        }
    }

    private function require(int $id): array
    {
        $contract = $this->contracts->findById($id);

        if ($contract === null) {
            throw new NotFoundException('Contract not found.');
        }

        return $contract;
    }

    private function requireCategory(int $id): array
    {
        $category = $this->categories->findById($id);

        if ($category === null) {
            throw new NotFoundException('Category not found.');
        }

        return $category;
    }
}
