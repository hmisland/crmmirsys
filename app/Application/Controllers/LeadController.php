<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\LeadService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Modules\FollowUpAccess;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

final class LeadController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly LeadService $leads,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly ?AuthorizationEngine $authorization = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'owner_id' => (string) $request->query('mine', '') === '1' ? (int) CurrentUser::id() : null,
        ];
        $pagination = Pagination::fromRequest($request, $this->leads->count(CurrentUser::user(), $filters));
        $leads = $this->leads->listPage(CurrentUser::user(), $filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('crm/leads/index', 'layout', [
            'leads' => $leads,
            'rowActions' => $this->leads->rowPermissions(CurrentUser::user(), $leads),
            'canCreateFollowUp' => FollowUpAccess::canCreate($this->authorization, CurrentUser::user()),
            'pagination' => $pagination,
            'mine' => $filters['owner_id'] !== null,
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->viewWithLayout('crm/leads/form', 'layout', ['lead' => null, 'errors' => [], 'old' => []]);
    }

    public function create(Request $request): Response
    {
        try {
            $lead = $this->leads->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/leads/form', 'layout', [
                'lead' => null,
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        $user = CurrentUser::user();
        foreach ((array) $request->input('tags', []) as $tag) {
            if (trim((string) $tag) !== '') {
                $this->tags->attach('lead', (int) $lead['id'], (string) $tag, (int) $user['id']);
            }
        }

        return $this->redirect('/leads/' . $lead['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $lead = $this->leads->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('crm/leads/show', 'layout', [
            'lead' => $lead,
            'notes' => $this->notes->forSubject('lead', $id),
            'tags' => $this->tags->forSubject('lead', $id),
            'allTags' => $this->tags->allForOrganization(),
            'timeline' => $this->timeline->forSubject('lead', $id),
            'canCreateFollowUp' => FollowUpAccess::canCreate($this->authorization, CurrentUser::user()),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->viewWithLayout('crm/leads/form', 'layout', [
            'lead' => $this->leads->find(CurrentUser::user(), $id),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->leads->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/leads/form', 'layout', [
                'lead' => $this->leads->find(CurrentUser::user(), $id),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Lead updated.');

        return $this->redirect('/leads/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->leads->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Lead deleted.');

        return $this->redirect('/leads');
    }

    public function convert(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $lead = $this->leads->convert(CurrentUser::user(), $id);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not convert lead.');

            return $this->redirect('/leads/' . $id);
        }

        Session::setFlash('success', 'Lead converted.');

        return $this->redirect('/contacts/' . $lead['converted_contact_id']);
    }

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->leads->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/leads');
        if (!str_starts_with($back, '/leads') || str_contains($back, '//')) {
            $back = '/leads';
        }

        return $this->redirect($back);
    }
}
