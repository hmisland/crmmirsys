<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Application\Services\TimelineService;
use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\NumberSeriesRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Records\RecordTypeRegistry;
use Modules\Projects\Events\ProjectCreated;
use Modules\Projects\Events\ProjectStatusChanged;
use Modules\Projects\ProjectLinkRepository;
use Modules\Projects\ProjectMemberRepository;
use Modules\Projects\ProjectPermission;
use Modules\Projects\ProjectRepository;
use Modules\Projects\ProjectSettingsRepository;

/**
 * Every project rule lives here; controllers stay thin. Uses Core only —
 * contacts, companies, users, timeline, number series, RBAC, EventBus.
 * No other module is referenced.
 *
 * Lifecycle (docs/projects-module-design.md B.4):
 *   draft ──plan──▶ planned ──start──▶ active ──complete──▶ completed ──reopen──▶ active
 *     └────────start──────────────────▶   │  ▲
 *                 planned/active ──hold──▶ on_hold ──resume──▶ active
 *   draft/planned/active/on_hold ──cancel──▶ cancelled
 *
 * Visibility: projects.view with the role's scope, WIDENED by membership —
 * a member of a project sees it even when the scope alone wouldn't allow.
 * Membership never grants editing: every write checks the permission on
 * the row through AuthorizationEngine (owner/team/department scope).
 *
 * Errors are ValidationException with translation keys as messages
 * (the controller translates them), same as ContractService.
 */
final class ProjectService
{
    public const STATUSES = ['draft', 'planned', 'active', 'on_hold', 'completed', 'cancelled'];
    private const EDITABLE = ['draft', 'planned', 'active', 'on_hold'];
    private const DELETABLE = ['draft', 'planned', 'cancelled'];

    /** action => [allowed from-statuses, to-status] */
    public const TRANSITIONS = [
        'plan' => [['draft'], 'planned'],
        'start' => [['draft', 'planned'], 'active'],
        'hold' => [['planned', 'active'], 'on_hold'],
        'resume' => [['on_hold'], 'active'],
        'complete' => [['active'], 'completed'],
        'cancel' => [['draft', 'planned', 'active', 'on_hold'], 'cancelled'],
        'reopen' => [['completed'], 'active'],
    ];

    private const NUMBER_TYPE = 'project';
    private const NUMBER_PREFIX = 'PRJ-';

    /** Record types a project may link to (RecordTypeRegistry keys of other modules). */
    public const LINKABLE = ['deal', 'contract'];

    /**
     * Contract ↔ project association rules (Stage 3-A, docs/project-finance-design.md).
     * Creating or removing a link to a CONTRACT needs projects.edit on the
     * project AND the contract module's own edit permission on the contract —
     * derived from its registered RecordType, never named here (that module is
     * never imported, and its key is not hard-coded). A new link
     * is refused while either side is in a closed state. Links to deals are
     * unchanged.
     */
    private const CONTRACT_STATUSES_CLOSED_TO_LINKS = ['terminated', 'cancelled'];
    private const PROJECT_STATUSES_CLOSED_TO_LINKS = ['completed', 'cancelled'];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectMemberRepository $members,
        private readonly NumberSeriesRepository $numbers,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly EventBus $events,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly UserRepository $users = new UserRepository(),
        private readonly ProjectLinkRepository $links = new ProjectLinkRepository(),
        private readonly ?RecordTypeRegistry $recordTypes = null,
        private readonly ProjectSettingsRepository $settings = new ProjectSettingsRepository(),
    ) {
    }

    // ---- Reading ---------------------------------------------------------

    /** One page of projects the user may see (scope + membership applied in SQL). */
    public function list(array $actingUser, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        return $this->projects->search($this->scoped($actingUser, $filters), $limit, $offset);
    }

    public function count(array $actingUser, array $filters = []): int
    {
        return $this->projects->countSearch($this->scoped($actingUser, $filters));
    }

    public function find(array $actingUser, int $id): array
    {
        $project = $this->require($id);
        if (!$this->canView($actingUser, $project)) {
            throw new AuthorizationException();
        }

        return $project;
    }

    /** Same rule as the list: view permission on the row, or membership (with projects.view held at all). */
    public function canView(array $actingUser, array $project): bool
    {
        if ($this->authorization->scopeFor($actingUser, ProjectPermission::VIEW) === null) {
            return false;
        }

        return $this->authorization->can($actingUser, ProjectPermission::VIEW, $project)
            || (!empty($actingUser['id']) && $this->members->isMember((int) $project['id'], (int) $actingUser['id']));
    }

    /** Page-level checks (e.g. show the "New project" button); record rules still apply on the action itself. */
    public function userCan(array $actingUser, string $permission): bool
    {
        return in_array($permission, ProjectPermission::ALL, true) && $this->authorization->can($actingUser, $permission);
    }

    /**
     * What this user may do with this project right now: permission on
     * the row AND current status.
     *
     * @return array<string, bool>
     */
    public function allowedActions(array $actingUser, array $project): array
    {
        $status = (string) $project['status'];
        $can = fn (string $key) => $this->authorization->can($actingUser, $key, $project);
        $edit = $can(ProjectPermission::EDIT);

        $actions = [
            'edit' => $edit && in_array($status, self::EDITABLE, true),
            'delete' => $can(ProjectPermission::DELETE) && in_array($status, self::DELETABLE, true),
            'manage_members' => $can(ProjectPermission::MANAGE_MEMBERS),
            // Links are metadata, allowed in any status.
            'link' => $edit,
        ];
        foreach (self::TRANSITIONS as $action => [$from]) {
            $actions[$action] = $edit && in_array($status, $from, true);
        }

        return $actions;
    }

    // ---- Writing ---------------------------------------------------------

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, ProjectPermission::CREATE)) {
            throw new AuthorizationException();
        }

        $fields = $this->validated($input, $actingUser);
        $fields += [
            'status' => 'draft',
            'created_by_user_id' => (int) $actingUser['id'],
        ];

        $id = $this->insert($fields, (int) $actingUser['id'], 'Project created');

        return $this->require($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $project = $this->require($id);
        $this->authorizeRecord($actingUser, ProjectPermission::EDIT, $project);
        $this->assertStatus($project, self::EDITABLE, 'projects.error.not_editable');

        $this->projects->updateProject($id, $this->validated($input, $actingUser, $project));
        $this->timeline->record('project', $id, 'updated', 'Project details updated', (int) $actingUser['id']);

        return $this->require($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $project = $this->require($id);
        $this->authorizeRecord($actingUser, ProjectPermission::DELETE, $project);
        $this->assertStatus($project, self::DELETABLE, 'projects.error.not_deletable');

        $this->projects->deleteProject($id);
    }

    /**
     * One lifecycle step (plan, start, hold, resume, complete, cancel,
     * reopen). complete sets actual_end_date (today unless given);
     * reopen clears it.
     */
    public function changeStatus(array $actingUser, int $id, string $action, ?string $actualEndDate = null): array
    {
        if (!isset(self::TRANSITIONS[$action])) {
            throw new ValidationException(['status' => ['projects.error.invalid_transition']]);
        }
        [$from, $to] = self::TRANSITIONS[$action];

        $project = $this->require($id);
        $this->authorizeRecord($actingUser, ProjectPermission::EDIT, $project);
        $this->assertStatus($project, $from, 'projects.error.invalid_transition');

        $fields = ['status' => $to];
        if ($action === 'complete') {
            $date = trim((string) $actualEndDate);
            if ($date !== '' && !$this->isIsoDate($date)) {
                throw new ValidationException(['actual_end_date' => ['projects.error.invalid_date']]);
            }
            $fields['actual_end_date'] = $date !== '' ? $date : date('Y-m-d');
        } elseif ($action === 'reopen') {
            $fields['actual_end_date'] = null;
        }

        $this->projects->updateProject($id, $fields);
        $this->timeline->record('project', $id, 'status_changed', 'Status changed from ' . $project['status'] . ' to ' . $to, (int) $actingUser['id']);
        $this->events->dispatch(new ProjectStatusChanged((int) $project['organization_id'], $id, (string) $project['status'], $to, (int) $actingUser['id']));

        return $this->require($id);
    }

    // ---- Members -----------------------------------------------------------

    public function members(array $actingUser, int $projectId): array
    {
        $this->find($actingUser, $projectId);

        return $this->members->forProject($projectId);
    }

    /** Active users of the organization who are neither the manager nor already members (for the add form). */
    public function memberCandidates(array $actingUser, array $project): array
    {
        $taken = array_map(fn (array $m) => (int) $m['user_id'], $this->members->forProject((int) $project['id']));
        $taken[] = (int) $project['owner_id'];

        return array_values(array_filter(
            $this->assignableUsers(),
            fn (array $u) => !in_array((int) $u['id'], $taken, true)
        ));
    }

    /** Active users of the current organization (owner and member pickers). */
    public function assignableUsers(): array
    {
        return array_values(array_filter(
            $this->users->findAllInOrganization(OrganizationContext::id()),
            fn (array $u) => ($u['status'] ?? '') === 'active'
        ));
    }

    /** Adds a member (idempotent). The user must be an active user of the same organization. */
    public function addMember(array $actingUser, int $projectId, int $userId): array
    {
        $project = $this->require($projectId);
        $this->authorizeRecord($actingUser, ProjectPermission::MANAGE_MEMBERS, $project);

        if ($this->activeOrgUser($userId) === null) {
            throw new ValidationException(['user_id' => ['projects.error.invalid_member']]);
        }

        $existing = $this->members->find($projectId, $userId);
        if ($existing !== null) {
            return $existing;
        }

        $this->members->create($projectId, $userId, (int) $actingUser['id']);
        $this->timeline->record('project', $projectId, 'member_added', 'Member added: user #' . $userId, (int) $actingUser['id']);

        return $this->members->find($projectId, $userId);
    }

    public function removeMember(array $actingUser, int $projectId, int $memberId): void
    {
        $project = $this->require($projectId);
        $this->authorizeRecord($actingUser, ProjectPermission::MANAGE_MEMBERS, $project);

        foreach ($this->members->forProject($projectId) as $member) {
            if ((int) $member['id'] === $memberId) {
                $this->members->deleteMember($memberId);
                $this->timeline->record('project', $projectId, 'member_removed', 'Member removed: user #' . $member['user_id'], (int) $actingUser['id']);

                return;
            }
        }

        throw new NotFoundException('Member not found.');
    }

    // ---- Links to other modules' records (deal, contract) -------------------
    //
    // A link points at a RecordTypeRegistry type and is validated through
    // RecordTypeRegistry::find(): the owning module must be available to the
    // user, who must be allowed to view that very record in their own
    // organization. Projects never imports the other module, and the other
    // module never learns about projects (the link lives here only).

    /**
     * Links a record to a project (idempotent). A deal link is allowed in any
     * status (metadata). A contract link (Stage 3-A) also needs the contract edit permission
     * on the contract, and neither side may be closed (contract terminated /
     * cancelled, project completed / cancelled). Two concurrent requests for the
     * same pair: the UNIQUE key stops the second insert, which then returns
     * the existing link — no raw database error, no second timeline entry.
     */
    public function link(array $actingUser, int $projectId, string $type, int $recordId): array
    {
        $project = $this->require($projectId);
        $this->authorizeRecord($actingUser, ProjectPermission::EDIT, $project);

        $target = $this->linkTarget($actingUser, $type, $recordId);
        if ($type === 'contract') {
            $this->assertContractLinkable($actingUser, $target, 'link');
            if (in_array((string) $project['status'], self::PROJECT_STATUSES_CLOSED_TO_LINKS, true)) {
                throw new ValidationException(['link' => ['projects.error.closed_project']]);
            }
        }

        $existing = $this->links->find($projectId, $type, $recordId);
        if ($existing !== null) {
            return $existing;
        }

        $label = $this->recordLabel($target, $type, $recordId);

        return $this->projects->atomically(function () use ($actingUser, $project, $projectId, $type, $recordId, $label): array {
            try {
                $this->links->create($projectId, $type, $recordId, $label, (int) $actingUser['id']);
            } catch (\PDOException $e) {
                // A concurrent request linked the same pair first: use that link.
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return $this->links->find($projectId, $type, $recordId) ?? throw $e;
                }
                throw $e;
            }
            $this->timeline->record('project', $projectId, 'linked', 'Linked to ' . $type . ' "' . $label . '"', (int) $actingUser['id']);
            if ($type === 'contract') {
                $this->timeline->record('contract', $recordId, 'project_linked', 'Project "' . $this->projectLabel($project) . '" linked', (int) $actingUser['id']);
            }

            return $this->links->find($projectId, $type, $recordId);
        });
    }

    /**
     * Removes one link (never the project, the contract, or anything financial).
     * A contract link also needs the contract edit permission on that contract, in any status;
     * when the contract no longer exists only projects.edit is needed. The
     * timeline is written only when THIS request actually deleted the row, so a
     * second, concurrent unlink leaves no false "unlinked" entry.
     *
     * @return array{type: string, record_id: int, url: ?string} the record that was linked
     */
    public function unlink(array $actingUser, int $projectId, int $linkId): array
    {
        $project = $this->require($projectId);
        $this->authorizeRecord($actingUser, ProjectPermission::EDIT, $project);

        $link = null;
        foreach ($this->links->forProject($projectId) as $candidate) {
            if ((int) $candidate['id'] === $linkId) {
                $link = $candidate;
                break;
            }
        }
        if ($link === null) {
            throw new NotFoundException('Link not found.');
        }

        $recordId = (int) $link['linked_id'];
        $contractExists = false;
        if ($link['linked_type'] === 'contract') {
            $contractExists = $this->contractExists($recordId);
            if ($contractExists) {
                $contract = $this->recordTypes?->find('contract', $recordId, $actingUser);
                if ($contract === null) {
                    throw new AuthorizationException();
                }
                $this->assertContractLinkable($actingUser, $contract, 'unlink');
            }
        }

        $this->projects->atomically(function () use ($actingUser, $project, $projectId, $linkId, $link, $recordId, $contractExists): void {
            if ($this->links->deleteLink($linkId) !== 1) {
                return; // already gone (a concurrent unlink): nothing happened here
            }
            $this->timeline->record('project', $projectId, 'unlinked', 'Unlinked ' . $link['linked_type'] . ' "' . $link['linked_label'] . '"', (int) $actingUser['id']);
            if ($contractExists) {
                $this->timeline->record('contract', $recordId, 'project_unlinked', 'Project "' . $this->projectLabel($project) . '" unlinked', (int) $actingUser['id']);
            }
        });

        $type = $this->recordTypes?->get((string) $link['linked_type']);

        return [
            'type' => (string) $link['linked_type'],
            'record_id' => $recordId,
            'url' => $type !== null && $this->recordTypes->find((string) $link['linked_type'], $recordId, $actingUser) !== null ? $type->showUrl($recordId) : null,
        ];
    }

    /**
     * Whether this user may link projects to this contract now (Stage 3-A): the
     * contract is viewable (find()), they hold the contract edit permission on it and it is
     * not terminated / cancelled. Non-contract types: true (unchanged rules).
     */
    public function canLinkRecord(array $actingUser, string $type, int $recordId): bool
    {
        if ($type !== 'contract') {
            return true;
        }
        $contract = $this->recordTypes?->find('contract', $recordId, $actingUser);
        if ($contract === null) {
            return false;
        }
        try {
            $this->assertContractLinkable($actingUser, $contract, 'link');
        } catch (AuthorizationException | ValidationException) {
            return false;
        }

        return true;
    }

    /** The owning module's page of a linked record (its RecordType), or null when that type isn't registered. */
    public function recordUrl(string $type, int $recordId): ?string
    {
        return $this->recordTypes?->get($type)?->showUrl($recordId);
    }

    /**
     * For the contract page: project id => link id of the links this user may
     * remove (projects.edit on the project + the contract edit permission on the contract).
     *
     * @param array<int, array> $projects rows already in the user's scope
     * @return array<int, int>
     */
    public function contractUnlinks(array $actingUser, int $contractId, array $projects): array
    {
        $contract = $this->recordTypes?->find('contract', $contractId, $actingUser);
        if ($contract === null || !$this->canEditContract($actingUser, $contract)) {
            return [];
        }
        $out = [];
        foreach ($projects as $p) {
            $link = $this->links->find((int) $p['id'], 'contract', $contractId);
            if ($link !== null && $this->authorization->can($actingUser, ProjectPermission::EDIT, $p)) {
                $out[(int) $p['id']] = (int) $link['id'];
            }
        }

        return $out;
    }

    /**
     * A project's links for display. `url` is set only when the target's
     * module is available and the user may view that record; otherwise the
     * stored label is shown without a link (module disabled, record gone…).
     *
     * @return array<int, array{id: int, type: string, record_id: int, label: string, label_key: ?string, url: ?string, can_unlink: bool}>
     */
    public function links(array $actingUser, int $projectId): array
    {
        $this->find($actingUser, $projectId);

        $out = [];
        foreach ($this->links->forProject($projectId) as $link) {
            $type = $this->recordTypes?->get($link['linked_type']);
            $live = $this->recordTypes?->find($link['linked_type'], (int) $link['linked_id'], $actingUser);
            $out[] = [
                'id' => (int) $link['id'],
                'type' => $link['linked_type'],
                'record_id' => (int) $link['linked_id'],
                'label' => $live !== null ? $this->recordLabel($live, $link['linked_type'], (int) $link['linked_id']) : $link['linked_label'],
                'label_key' => $type?->labelKey,
                'url' => $live !== null ? $type->showUrl((int) $link['linked_id']) : null,
                // Stage 3-A: a contract link also needs the contract edit permission on that contract (unless it is gone).
                'can_unlink' => $link['linked_type'] !== 'contract'
                    || ($live !== null ? $this->canEditContract($actingUser, $live) : !$this->contractExists((int) $link['linked_id'])),
            ];
        }

        return $out;
    }

    /** Projects linked to one record of another module, within the user's view scope. */
    public function linkedTo(array $actingUser, string $type, int $recordId, ?int $limit = null): array
    {
        $ids = $this->links->projectIdsLinkedTo($type, $recordId);

        return $ids === [] ? [] : $this->list($actingUser, ['id_in' => $ids], $limit);
    }

    public function countLinkedTo(array $actingUser, string $type, int $recordId): int
    {
        $ids = $this->links->projectIdsLinkedTo($type, $recordId);

        return $ids === [] ? 0 : $this->count($actingUser, ['id_in' => $ids]);
    }

    /**
     * Projects this user may link to $type #$recordId: ones they may edit
     * and that aren't linked to it yet (newest first, at most $limit).
     */
    public function linkableProjects(array $actingUser, string $type, int $recordId, int $limit = 100): array
    {
        $linked = $this->links->projectIdsLinkedTo($type, $recordId);
        // A contract: only while this user may link to it, and never a completed / cancelled project (Stage 3-A).
        if (!$this->canLinkRecord($actingUser, $type, $recordId)) {
            return [];
        }

        return array_values(array_filter(
            $this->list($actingUser, [], $limit),
            fn (array $p) => !in_array((int) $p['id'], $linked, true) && $this->authorization->can($actingUser, ProjectPermission::EDIT, $p)
                && ($type !== 'contract' || !in_array((string) $p['status'], self::PROJECT_STATUSES_CLOSED_TO_LINKS, true))
        ));
    }

    // ---- Create from a deal or a contract --------------------------------

    /**
     * Form prefill for "New project" on a deal or contract page, or null
     * when that record isn't available to this user (module off, no view
     * permission, another organization, outside record scope). Reads the
     * record only through RecordTypeRegistry::find(). create() validates
     * everything again anyway.
     *
     * @return array<string, string>|null
     */
    public function draftFrom(array $actingUser, string $type, int $recordId): ?array
    {
        if (!in_array($type, self::LINKABLE, true) || $this->recordTypes === null) {
            return null;
        }

        $record = $this->recordTypes->find($type, $recordId, $actingUser);
        if ($record === null) {
            return null;
        }
        // From a contract this flow creates the link too (Stage 3-A): same rules.
        if ($type === 'contract' && !$this->canLinkRecord($actingUser, $type, $recordId)) {
            return null;
        }

        $label = $this->recordLabel($record, $type, $recordId);
        $draft = [
            'name' => $label,
            'contact_id' => !empty($record['contact_id']) ? (string) $record['contact_id'] : '',
            'company_id' => !empty($record['company_id']) ? (string) $record['company_id'] : '',
            'source_type' => $type,
            'source_id' => (string) $recordId,
            'source_label' => $label,
            // The page URL is the owning module's own (its RecordType), never hard-coded here.
            'source_url' => $this->recordTypes->get($type)->showUrl($recordId),
        ];

        // A contract's period becomes the project's planned period.
        if ($type === 'contract') {
            $draft['start_date'] = (string) ($record['start_date'] ?? '');
            $draft['expected_end_date'] = (string) ($record['end_date'] ?? '');
        }

        return $draft;
    }

    /**
     * create() + link to the source record, atomically. The source is
     * re-checked here (hidden form fields are never trusted): if the user
     * can't see that record, nothing is created. Linking is part of
     * creating — it needs projects.create, not projects.edit.
     */
    public function createLinked(array $actingUser, array $input, string $sourceType, int $sourceId): array
    {
        try {
            $target = $this->linkTarget($actingUser, $sourceType, $sourceId);
        } catch (ValidationException) {
            throw new ValidationException(['source' => ['projects.error.link_target_invalid']]);
        }
        // From a contract this creates the link (Stage 3-A): the contract edit permission on it
        // (403) and an open contract; a new project is never completed / cancelled.
        if ($sourceType === 'contract') {
            try {
                $this->assertContractLinkable($actingUser, $target, 'link');
            } catch (ValidationException $e) {
                throw new ValidationException(['source' => array_values($e->errors)[0]]);
            }
        }

        return $this->projects->atomically(function () use ($actingUser, $input, $sourceType, $sourceId, $target): array {
            $project = $this->create($actingUser, $input);
            $label = $this->recordLabel($target, $sourceType, $sourceId);
            $this->links->create((int) $project['id'], $sourceType, $sourceId, $label, (int) $actingUser['id']);
            $this->timeline->record('project', (int) $project['id'], 'linked', 'Created from ' . $sourceType . ' "' . $label . '"', (int) $actingUser['id']);
            if ($sourceType === 'contract') {
                $this->timeline->record('contract', $sourceId, 'project_linked', 'Project "' . $this->projectLabel($project) . '" created from this contract', (int) $actingUser['id']);
            }

            return $project;
        });
    }

    // ---- Organization settings + auto-create on won deals (decision D4) -----

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
     * Contracts' auto-create, it needs no projects.create from anyone — but
     * it only runs when the organization switched it on, creates at most ONE
     * project per deal (none if any project is already linked to it), and
     * reads the deal through RecordTypeRegistry as the person who won it (so
     * Sales availability and their deals.view still apply).
     *
     * @return int|null the new draft's id, or null when nothing was created
     */
    public function autoCreateFromWonDeal(int $dealId, ?int $actorUserId): ?int
    {
        if (empty($this->settings->current()['auto_create_on_deal_won']) || $actorUserId === null || $this->recordTypes === null) {
            return null;
        }

        if ($this->links->projectIdsLinkedTo('deal', $dealId) !== []) {
            return null; // one project per deal: never a duplicate on a second "won"
        }

        $actor = $this->activeOrgUser($actorUserId);
        $deal = $actor !== null ? $this->recordTypes->find('deal', $dealId, $actor) : null;
        $draft = $actor !== null ? $this->draftFrom($actor, 'deal', $dealId) : null;
        if ($actor === null || $deal === null || $draft === null) {
            return null;
        }

        $owner = !empty($deal['owner_id']) ? ($this->activeOrgUser((int) $deal['owner_id']) ?? $actor) : $actor;
        $draft['owner_id'] = (string) $owner['id'];
        try {
            $fields = $this->validated($draft, $actor);
        } catch (ValidationException) {
            $fields = $this->validated(['name' => $draft['name'] !== '' ? mb_substr($draft['name'], 0, 255) : 'Deal #' . $dealId, 'owner_id' => (string) $owner['id']], $actor);
        }
        $fields += [
            'status' => 'draft',
            'created_by_user_id' => (int) $actor['id'],
        ];

        $label = $this->recordLabel($deal, 'deal', $dealId);

        return $this->projects->atomically(function () use ($fields, $dealId, $label, $actor): int {
            $id = $this->insert($fields, (int) $actor['id'], 'Created automatically when deal "' . $label . '" was won');
            $this->links->create($id, 'deal', $dealId, $label, (int) $actor['id']);

            return $id;
        });
    }

    // ---- Internals ---------------------------------------------------------

    private function authorizeSettings(array $actingUser): void
    {
        if (!$this->authorization->can($actingUser, ProjectPermission::MANAGE_SETTINGS)) {
            throw new AuthorizationException();
        }
    }

    /** The other module's record, or ValidationException when the type isn't linkable or the record isn't viewable. */
    private function linkTarget(array $actingUser, string $type, int $recordId): array
    {
        $target = in_array($type, self::LINKABLE, true) ? $this->recordTypes?->find($type, $recordId, $actingUser) : null;
        if ($target === null) {
            throw new ValidationException(['link' => ['projects.error.link_target_invalid']]);
        }

        return $target;
    }

    /**
     * Contract side of a link (Stage 3-A): the contract edit permission on that contract
     * (403 otherwise); for a NEW link also an open contract — terminated and
     * cancelled refuse. Expired is open (the stored status stays `active`;
     * Contracts computes `expired` from the end date), as are draft, pending
     * approval and active. $mode 'unlink' checks the permission only.
     */
    private function assertContractLinkable(array $actingUser, array $contract, string $mode): void
    {
        if (!$this->canEditContract($actingUser, $contract)) {
            throw new AuthorizationException();
        }
        if ($mode === 'link' && in_array((string) ($contract['status'] ?? ''), self::CONTRACT_STATUSES_CLOSED_TO_LINKS, true)) {
            throw new ValidationException(['link' => ['projects.error.closed_contract']]);
        }
    }

    /**
     * Whether the contract row still exists in this organization (the
     * registry's own finder, tenant-scoped). A link to a contract that was
     * deleted can be removed with projects.edit alone; an existing contract
     * always needs the contract edit permission. Contracts not registered → can't tell →
     * treated as existing (so the permission is required and fails closed).
     */
    private function contractExists(int $contractId): bool
    {
        $finder = $this->recordTypes?->get('contract')?->finder;

        return $finder === null || $finder($contractId) !== null;
    }

    /**
     * The contract module's edit permission on this contract record (scope
     * applies). Its key comes from that module's registered RecordType — the
     * `<module>.view` key it registered, as `<module>.edit` — so Projects never
     * names the other module. No such type or key → false (fails closed).
     */
    private function canEditContract(array $actingUser, array $contract): bool
    {
        $view = (string) ($this->recordTypes?->get('contract')?->viewPermission ?? '');
        if (!str_ends_with($view, '.view')) {
            return false;
        }

        return $this->authorization->can($actingUser, substr($view, 0, -strlen('.view')) . '.edit', $contract);
    }

    private function projectLabel(array $project): string
    {
        $code = trim((string) ($project['code'] ?? ''));

        return (string) $project['name'] . ($code !== '' ? ' (' . $code . ')' : '');
    }

    /** A readable name for another module's record, without knowing its schema in advance. */
    private function recordLabel(array $record, string $type, int $id): string
    {
        foreach (['name', 'title', 'subject'] as $field) {
            if (!empty($record[$field])) {
                return (string) $record[$field];
            }
        }

        return $type . ' #' . $id;
    }

    /** Numbers and inserts atomically (the number is consumed only if the row is saved), then records + dispatches. */
    private function insert(array $fields, int $actorId, string $timelineText): int
    {
        $id = $this->projects->atomically(function () use ($fields, $actorId, $timelineText): int {
            $fields['code'] = $this->numbers->next(self::NUMBER_TYPE, self::NUMBER_PREFIX);
            $id = $this->projects->create($fields);
            $this->timeline->record('project', $id, 'created', $timelineText, $actorId);

            return $id;
        });

        $project = $this->require($id);
        $this->events->dispatch(new ProjectCreated((int) $project['organization_id'], $id, (string) $project['code'], $actorId));

        return $id;
    }

    /** A user of the current organization who is active, else null. */
    private function activeOrgUser(int $userId): ?array
    {
        $user = $userId > 0 ? $this->users->findById($userId) : null;

        return $user !== null && ($user['status'] ?? '') === 'active' && (int) $user['organization_id'] === OrganizationContext::id()
            ? $user
            : null;
    }

    /**
     * Filters + the user's view scope. OWN/TEAM/DEPARTMENT become SQL
     * filters mirroring AuthorizationEngine::can() on a single row, each
     * OR "is a member" — so the list never shows a row find() refuses,
     * and never hides one it allows.
     */
    private function scoped(array $actingUser, array $filters): array
    {
        $scope = $this->authorization->scopeFor($actingUser, ProjectPermission::VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $visible = fn (string $column, $value) => array_merge($filters, ['visible_to' => [
            'user_id' => (int) ($actingUser['id'] ?? 0),
            'column' => $column,
            'value' => $value,
        ]]);

        return match ($scope) {
            Scope::OWN => $visible('owner_id', (int) $actingUser['id']),
            Scope::TEAM => $visible('team_id', $actingUser['team_id'] ?? null),
            Scope::DEPARTMENT => $visible('department_id', $actingUser['department_id'] ?? null),
            default => $filters,
        };
    }

    /**
     * Columns from user input; throws ValidationException with translation keys.
     * The manager (owner_id) defaults to the acting user on create and to the
     * current manager on edit; team/department follow the manager, so RBAC
     * team/department scope matches the person running the project.
     *
     * @return array<string, mixed>
     */
    private function validated(array $input, array $actingUser, ?array $current = null): array
    {
        $errors = [];
        $text = fn (string $key) => isset($input[$key]) ? trim((string) $input[$key]) : '';
        $intOrNull = fn (string $key) => $text($key) === '' ? null : (int) $text($key);

        $name = $text('name');
        if ($name === '') {
            $errors['name'][] = 'projects.error.name_required';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'][] = 'projects.error.name_too_long';
        }

        // Core contacts/companies, tenant-scoped: another org's id is "not found".
        $contactId = $intOrNull('contact_id');
        if ($contactId !== null && $this->contacts->findById($contactId) === null) {
            $errors['contact_id'][] = 'projects.error.invalid_contact';
        }
        $companyId = $intOrNull('company_id');
        if ($companyId !== null && $this->companies->findById($companyId) === null) {
            $errors['company_id'][] = 'projects.error.invalid_company';
        }

        $ownerId = $intOrNull('owner_id') ?? (int) ($current['owner_id'] ?? $actingUser['id']);
        $owner = $this->activeOrgUser($ownerId);
        // Keeping the current manager is always allowed (even if they were since deactivated).
        if ($owner === null && $current !== null && $ownerId === (int) $current['owner_id']) {
            $owner = ['id' => $ownerId, 'team_id' => $current['team_id'], 'department_id' => $current['department_id']];
        }
        if ($owner === null) {
            $errors['owner_id'][] = 'projects.error.invalid_owner';
        }

        $dates = [];
        foreach (['start_date', 'expected_end_date'] as $key) {
            $dates[$key] = null;
            if ($text($key) !== '') {
                if ($this->isIsoDate($text($key))) {
                    $dates[$key] = $text($key);
                } else {
                    $errors[$key][] = 'projects.error.invalid_date';
                }
            }
        }
        if ($dates['start_date'] !== null && $dates['expected_end_date'] !== null && $dates['expected_end_date'] < $dates['start_date']) {
            $errors['expected_end_date'][] = 'projects.error.end_before_start';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'description' => $text('description') === '' ? null : $text('description'),
            'contact_id' => $contactId,
            'company_id' => $companyId,
            'owner_id' => (int) $owner['id'],
            'team_id' => $owner['team_id'] ?? null,
            'department_id' => $owner['department_id'] ?? null,
            'start_date' => $dates['start_date'],
            'expected_end_date' => $dates['expected_end_date'],
        ];
    }

    private function isIsoDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function assertStatus(array $project, array $allowed, string $errorKey): void
    {
        if (!in_array((string) $project['status'], $allowed, true)) {
            throw new ValidationException(['status' => [$errorKey]]);
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function require(int $id): array
    {
        $project = $this->projects->findById($id);

        if ($project === null) {
            throw new NotFoundException('Project not found.');
        }

        return $project;
    }
}
