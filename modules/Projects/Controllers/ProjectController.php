<?php

declare(strict_types=1);

namespace Modules\Projects\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Projects\ProjectPermission;
use Modules\Projects\Services\ProjectService;

use function App\Infrastructure\Theme\t;

/**
 * Project pages. Thin: every rule — permission, record scope, membership,
 * status, validation — is ProjectService's. Routes are wrapped in
 * ModuleMiddleware('projects') + RbacMiddleware (ProjectsModule).
 * Service errors are translation keys; translated here.
 */
final class ProjectController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProjectService $projects,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly AttachmentService $attachments,
        private readonly ContactRepository $contactRepository,
        private readonly CompanyRepository $companyRepository,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $status = (string) $request->query('status', '');
        $filters = [
            'search' => trim((string) $request->query('q', '')),
            'status' => in_array($status, ProjectService::STATUSES, true) ? $status : '',
            'involving_user_id' => (string) $request->query('mine', '') === '1' ? (int) $user['id'] : null,
            // From the contact/company panels' "view all" link.
            'contact_id' => (int) $request->query('contact', 0) ?: null,
            'company_id' => (int) $request->query('company', 0) ?: null,
        ];

        $pagination = Pagination::fromRequest($request, $this->projects->count($user, $filters));
        $rows = $this->projects->list($user, $filters, $pagination->perPage, $pagination->offset());

        $rowActions = [];
        foreach ($rows as $row) {
            $allowed = $this->projects->allowedActions($user, $row);
            $rowActions[(int) $row['id']] = ['edit' => $allowed['edit'], 'delete' => $allowed['delete'], 'reason' => t('projects.locked_reason')];
        }

        return $this->viewWithLayout('projects/index', 'layout', [
            'projects' => $rows,
            'rowActions' => $rowActions,
            'pagination' => $pagination,
            'q' => (string) $request->query('q', ''),
            'status' => $filters['status'],
            'mine' => $filters['involving_user_id'] !== null,
            'partyFilter' => array_filter(['contact' => $filters['contact_id'], 'company' => $filters['company_id']]),
            'canCreate' => $this->can(ProjectPermission::CREATE),
            'canManageSettings' => $this->can(ProjectPermission::MANAGE_SETTINGS),
            'fmt' => $this->formatter(),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        // "New project" on a deal or contract page: prefill from that record,
        // read through RecordTypeRegistry. A record the user can't see → 404.
        foreach (['from_deal' => 'deal', 'from_contract' => 'contract'] as $param => $type) {
            $sourceId = (int) $request->query($param, 0);
            if ($sourceId > 0) {
                $draft = $this->projects->draftFrom(CurrentUser::user(), $type, $sourceId);
                if ($draft === null) {
                    throw new NotFoundException();
                }

                return $this->form(null, [], $draft);
            }
        }

        // Optional prefill, e.g. from a contact or company page (?contact_id=…).
        $old = array_filter([
            'contact_id' => (string) $request->query('contact_id', ''),
            'company_id' => (string) $request->query('company_id', ''),
        ], fn ($v) => $v !== '');

        return $this->form(null, [], $old);
    }

    public function create(Request $request): Response
    {
        $input = $request->all();
        $sourceType = (string) ($input['source_type'] ?? '');
        $sourceId = (int) ($input['source_id'] ?? 0);

        try {
            $project = $sourceType !== '' && $sourceId > 0
                ? $this->projects->createLinked(CurrentUser::user(), $input, $sourceType, $sourceId)
                : $this->projects->create(CurrentUser::user(), $input);
        } catch (ValidationException $e) {
            return $this->form(null, $this->translated($e->errors), $input, 422);
        }

        Session::setFlash('success', t('projects.flash.created'));

        return $this->redirect('/projects/' . $project['id']);
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');
        $project = $this->projects->find($user, $id);
        $actions = $this->projects->allowedActions($user, $project);

        return $this->viewWithLayout('projects/show', 'layout', [
            'project' => $project,
            'actions' => $actions,
            'owner' => $this->userName((int) $project['owner_id']),
            'contact' => $project['contact_id'] !== null ? $this->contactRepository->findById((int) $project['contact_id']) : null,
            'company' => $project['company_id'] !== null ? $this->companyRepository->findById((int) $project['company_id']) : null,
            'members' => $this->projects->members($user, $id),
            'candidates' => $actions['manage_members'] ? $this->projects->memberCandidates($user, $project) : [],
            'links' => $this->projects->links($user, $id),
            'notes' => $this->notes->forSubject('project', $id),
            'tags' => $this->tags->forSubject('project', $id),
            'allTags' => $this->tags->allForOrganization(),
            'timeline' => $this->timeline->forSubject('project', $id),
            'attachments' => $this->attachments->forSubject('project', $id),
            'fmt' => $this->formatter(),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $user = CurrentUser::user();
        $project = $this->projects->find($user, (int) $request->attribute('id'));

        // Never show a form the user can't save: same rule update() enforces.
        if (!$this->projects->allowedActions($user, $project)['edit']) {
            throw new AuthorizationException();
        }

        return $this->form($project, [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->projects->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->form($this->projects->find(CurrentUser::user(), $id), $this->translated($e->errors), $request->all(), 422);
        }

        Session::setFlash('success', t('projects.flash.updated'));

        return $this->redirect('/projects/' . $id);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->projects->delete(CurrentUser::user(), $id);
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t('projects.flash.deleted'));

        return $this->redirect('/projects');
    }

    /** POST /projects/{id}/status, action = plan|start|hold|resume|complete|cancel|reopen. */
    public function status(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $action = (string) $request->input('action', '');

        try {
            $this->projects->changeStatus(CurrentUser::user(), $id, $action, (string) $request->input('actual_end_date', ''));
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t('projects.flash.status_changed'));

        return $this->redirect('/projects/' . $id);
    }

    public function addMember(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->projects->addMember(CurrentUser::user(), $id, (int) $request->input('user_id', 0));
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t('projects.flash.member_added'));

        return $this->redirect('/projects/' . $id);
    }

    public function removeMember(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->projects->removeMember(CurrentUser::user(), $id, (int) $request->attribute('memberId'));
        Session::setFlash('success', t('projects.flash.member_removed'));

        return $this->redirect('/projects/' . $id);
    }

    /** POST /projects/links — "Link existing project" from a deal or contract panel (project_id chosen in the form). */
    public function link(Request $request): Response
    {
        $id = (int) $request->input('project_id', 0);

        try {
            $this->projects->link(CurrentUser::user(), $id, (string) $request->input('linked_type', ''), (int) $request->input('linked_id', 0));
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t('projects.flash.linked'));

        return $this->redirect('/projects/' . $id);
    }

    public function unlink(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $linked = $this->projects->unlink(CurrentUser::user(), $id, (int) $request->attribute('linkId'));
        Session::setFlash('success', t('projects.flash.unlinked'));

        // From the contract page (Stage 3-A): back there — but only to the page of
        // the record that was linked, as derived on the server (never an open redirect).
        $back = (string) $request->input('return_to', '');

        return $this->redirect($back !== '' && $back === $linked['url'] ? $back : '/projects/' . $id);
    }

    // ---- helpers -----------------------------------------------------------

    private function backWithError(int $id, ValidationException $e): Response
    {
        $first = array_values($e->errors)[0][0] ?? 'projects.error.invalid_transition';
        Session::setFlash('error', t($first));

        return $this->redirect('/projects/' . $id);
    }

    private function form(?array $project, array $errors, array $old, int $status = 200): Response
    {
        // The "created from" notice is re-derived on the server, never taken from the form.
        $source = null;
        $sourceType = (string) ($old['source_type'] ?? '');
        if ($project === null && in_array($sourceType, ProjectService::LINKABLE, true) && (int) ($old['source_id'] ?? 0) > 0) {
            $draft = $this->projects->draftFrom(CurrentUser::user(), $sourceType, (int) $old['source_id']);
            $source = $draft === null ? null : ['type' => $sourceType, 'id' => (int) $old['source_id'], 'label' => $draft['source_label'], 'url' => $draft['source_url']];
        }
        unset($old['source_label'], $old['source_url']);

        return $this->viewWithLayout('projects/form', 'layout', [
            'source' => $source,
            'project' => $project,
            'errors' => $errors,
            'old' => $old,
            'contacts' => $this->contactRepository->search([]),
            'companies' => $this->companyRepository->search([]),
            'users' => $this->projects->assignableUsers(),
            'defaultOwnerId' => (int) CurrentUser::user()['id'],
        ], $errors === [] ? $status : 422);
    }

    /** @param array<string, string[]> $errors translation keys */
    private function translated(array $errors): array
    {
        return array_map(fn (array $messages) => array_map(fn (string $key) => t($key), $messages), $errors);
    }

    private function userName(int $userId): string
    {
        foreach ($this->projects->assignableUsers() as $u) {
            if ((int) $u['id'] === $userId) {
                return trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''));
            }
        }

        return '#' . $userId;
    }

    /** Jalali dates for fa, locale digits/grouping — same as the Contracts pages. */
    private function formatter(): LocaleFormatter
    {
        $translator = Locale::get();

        return new LocaleFormatter($translator !== null ? $translator->locale() : 'en');
    }

    private function can(string $permission): bool
    {
        return $this->projects->userCan(CurrentUser::user(), $permission);
    }
}
