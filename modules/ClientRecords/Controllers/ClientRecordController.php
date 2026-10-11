<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\ClientRecords\Services\ClientFileService;
use Modules\ClientRecords\Services\ClientIntegrations;
use Modules\ClientRecords\Services\ClientRecordService;
use Modules\ClientRecords\Services\ClientTimelineService;
use Modules\ClientRecords\Services\ClientTreatmentService;

use function App\Infrastructure\Theme\t;

/**
 * List, create/edit, and the tabbed record page. The record page is
 * server-side tabbed (?tab=...) so each tab is a deep link, works
 * without JS, and only loads what that tab shows. This controller only
 * assembles view models — every rule lives in the services.
 */
final class ClientRecordController extends BaseController
{
    public const TABS = ['overview', 'intake', 'services', 'sessions', 'appointments', 'followups', 'invoices', 'documents', 'media', 'timeline'];

    public function __construct(
        TemplateEngine $templates,
        private readonly ClientRecordService $records,
        private readonly ClientTreatmentService $treatment,
        private readonly ClientFileService $files,
        private readonly ClientTimelineService $timeline,
        private readonly ClientIntegrations $integrations,
        private readonly ContactRepository $contacts,
        private readonly AuthorizationEngine $authorization,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ClientRecordService::STATUSES, true) ? (string) $request->query('status') : '',
            'assigned_user_id' => (int) $request->query('assigned_user_id', 0) ?: '',
        ];

        $rows = $this->records->list($user, $filters);

        // KPIs come from the already scope-filtered list of ALL
        // statuses, so they never count records the user can't see.
        $unfiltered = $filters['q'] === '' && $filters['status'] === '' && $filters['assigned_user_id'] === '';
        $all = $unfiltered ? $rows : $this->records->list($user, []);
        $soon = date('Y-m-d H:i:s', strtotime('+7 days'));

        // KPIs above use every row; the table shows one page.
        [$rows, $pagination] = Pagination::slice($rows, $request);

        return $this->viewWithLayout('client-records/index', 'layout', [
            'rows' => $rows,
            'canDelete' => $this->records->deletableIds($user, $rows),
            'pagination' => $pagination,
            'filters' => $filters,
            'users' => $this->records->organizationUserNames($user),
            'kpis' => [
                'active' => count(array_filter($all, fn ($r) => $r['status'] === 'active')),
                'total' => count($all),
                'upcoming' => count(array_filter($all, fn ($r) => $r['next_visit_at'] !== null && $r['next_visit_at'] <= $soon)),
                'mine' => count(array_filter($all, fn ($r) => $r['assigned_user_id'] === $user['id'])),
            ],
            'canCreate' => $this->authorization->can($user, Permission::CLIENT_RECORDS_CREATE),
            'canExport' => $this->authorization->can($user, Permission::CLIENT_RECORDS_EXPORT),
        ]);
    }

    /** CSV of the visible list. Deliberately excludes every sensitive field. */
    public function export(Request $request): Response
    {
        $user = CurrentUser::user();
        if (!$this->authorization->can($user, Permission::CLIENT_RECORDS_EXPORT)) {
            throw new AuthorizationException();
        }

        $users = $this->records->organizationUserNames($user);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps read Persian/Arabic correctly
        fputcsv($out, [
            t('client_records.record_number'), t('common.name'), t('common.phone'), t('common.email'),
            t('client_records.status'), t('client_records.assigned_specialist'),
            t('client_records.last_visit'), t('client_records.next_visit'), t('client_records.opened_on'),
        ]);

        foreach ($this->records->list($user, ['q' => (string) $request->query('q', ''), 'limit' => 1000]) as $row) {
            fputcsv($out, [
                $row['record_number'],
                trim($row['contact_first_name'] . ' ' . ($row['contact_last_name'] ?? '')),
                $row['contact_phone'],
                $row['contact_email'],
                t('client_records.status.' . $row['status']),
                $users[$row['assigned_user_id']] ?? '',
                $row['last_visit_at'],
                $row['next_visit_at'],
                substr((string) $row['created_at'], 0, 10),
            ]);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return Response::html($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="client-records-' . date('Y-m-d') . '.csv"');
    }

    /** From a contact page: open its record, or start one for it. */
    public function forContact(Request $request): Response
    {
        $contactId = (int) $request->attribute('contactId');
        $existing = $this->records->list(CurrentUser::user(), ['contact_id' => $contactId, 'limit' => 1]);

        if ($existing !== []) {
            return $this->redirect('/client-records/' . (int) $existing[0]['id']);
        }

        return $this->redirect('/client-records/create?contact_id=' . $contactId);
    }

    public function showCreate(Request $request): Response
    {
        return $this->form(null, ['contact_id' => (string) $request->query('contact_id', '')], []);
    }

    public function create(Request $request): Response
    {
        try {
            $record = $this->records->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->form(null, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('client_records.flash.created'));

        return $this->redirect('/client-records/' . (int) $record['id']);
    }

    public function showEdit(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $this->records->authorizeRecord($user, Permission::CLIENT_RECORDS_EDIT, $record);

        $old = $this->records->presentable($user, $record);
        $old['custom'] = $this->records->customFieldValues((int) $record['id']);

        return $this->form($record, $old, []);
    }

    public function update(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');

        try {
            $this->records->update($user, $id, $request->all());
        } catch (ValidationException $e) {
            return $this->form($this->records->find($user, $id), $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('client_records.flash.updated'));

        return $this->redirect('/client-records/' . $id . '?tab=intake');
    }

    public function changeStatus(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $status = (string) $request->input('status', '');

        try {
            $this->records->changeStatus(CurrentUser::user(), $id, $status);
            Session::setFlash('success', t('client_records.flash.status_changed', ['status' => t('client_records.status.' . $status)]));
        } catch (ValidationException $e) {
            Session::setFlash('error', t($this->firstError($e)));
        }

        return $this->redirect('/client-records/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->records->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('client_records.flash.deleted'));

        return $this->redirect('/client-records');
    }

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->records->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/client-records');
        if (!str_starts_with($back, '/client-records') || str_contains($back, '//')) {
            $back = '/client-records';
        }

        return $this->redirect($back);
    }

    public function addNote(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->records->addNote(CurrentUser::user(), $id, (string) $request->input('body', ''));
            Session::setFlash('success', t('client_records.flash.note_added'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t($this->firstError($e)));
        }

        return $this->redirect('/client-records/' . $id . '#notes');
    }

    public function deleteNote(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->records->deleteNote(CurrentUser::user(), $id, (int) $request->attribute('noteId'));

        return $this->redirect('/client-records/' . $id . '#notes');
    }

    /** Quick follow-up from the record — created by the Follow-ups module itself. */
    public function createFollowUp(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));

        if (!$this->integrations->canCreateFollowUp($user)) {
            throw new AuthorizationException();
        }

        try {
            // The assignee must be a member of this organization.
            $assignee = $this->records->validatedUserId($user, $request->input('assignee_user_id'), 'assignee_user_id');

            $this->integrations->createFollowUp($user, (int) $record['contact_id'], [
                'title' => (string) $request->input('title', ''),
                'type' => (string) $request->input('type', 'call'),
                'priority' => (string) $request->input('priority', 'normal'),
                'due_at' => (string) $request->input('due_at', ''),
                'description' => (string) $request->input('description', ''),
                'assignee_user_id' => $assignee !== null ? (string) $assignee : '',
            ]);
            Session::setFlash('success', t('client_records.flash.followup_created'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t(isset($e->errors['assignee_user_id']) ? 'client_records.validation.invalid_user' : 'client_records.validation.followup_title_required'));
        }

        return $this->redirect('/client-records/' . (int) $record['id'] . '?tab=followups');
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview';
        $recordId = (int) $record['id'];

        $services = $this->treatment->servicesFor($record);
        $sessions = $this->treatment->sessionsFor($user, $record);
        $invoices = $this->integrations->invoicesForContact($user, (int) $record['contact_id']);

        $data = [
            'tab' => $tab,
            'record' => $this->records->presentable($user, $record),
            'contact' => $this->records->contactFor($record),
            'users' => $this->records->organizationUserNames($user),
            'services' => $services,
            'sessions' => $sessions,
            'servicesById' => array_column($services, null, 'id'),
            'invoices' => $invoices,
            'summary' => $this->summary($services, $sessions, $invoices),
            'can' => [
                'edit' => $this->records->can($user, Permission::CLIENT_RECORDS_EDIT, $record),
                'delete' => $this->records->can($user, Permission::CLIENT_RECORDS_DELETE, $record),
                'sensitive' => $this->records->canViewSensitive($user, $record),
                'sessions' => $this->records->can($user, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record),
                'media' => $this->records->can($user, Permission::CLIENT_RECORDS_MANAGE_MEDIA, $record),
                'documents' => $this->records->can($user, Permission::CLIENT_RECORDS_MANAGE_DOCUMENTS, $record),
                'followups' => $this->integrations->followUpsAvailable($user),
                'create_followup' => $this->integrations->canCreateFollowUp($user),
                'invoices' => $this->integrations->invoicesAvailable($user),
                'payments' => $this->integrations->paymentsAvailable($user),
                'create_invoice' => $this->integrations->invoicesAvailable($user) && $this->authorization->can($user, Permission::INVOICES_CREATE),
                'contact' => $this->authorization->can($user, Permission::CONTACTS_VIEW),
            ],
        ];

        if (in_array($tab, ['overview', 'followups'], true)) {
            $data['followUps'] = $this->integrations->followUpsForContact($user, (int) $record['contact_id']);
        }

        if (in_array($tab, ['overview', 'invoices', 'timeline'], true)) {
            $data['payments'] = $this->integrations->paymentsForInvoices($user, $invoices);
            $data['timeline'] = $tab === 'invoices' ? [] : $this->timeline->build($user, $record, $invoices, $data['payments'], $tab === 'overview' ? 6 : 200);
        }

        if ($tab === 'overview') {
            $data['notes'] = $this->records->notesFor($recordId);
        }

        if ($tab === 'intake') {
            $data['customFieldDefs'] = $this->records->customFieldDefinitions();
            $data['customFieldValues'] = $this->records->customFieldValues($recordId);
        }

        if ($tab === 'documents' || $tab === 'media') {
            $data['files'] = $this->files->filesFor($user, $record, $tab === 'media' ? 'media' : 'document');
            $data['mediaFilter'] = in_array($request->query('category'), \Modules\ClientRecords\Services\ClientFileService::MEDIA_CATEGORIES, true)
                ? (string) $request->query('category') : '';
        }

        return $this->viewWithLayout('client-records/show', 'layout', $data);
    }

    // ---------------------------------------------------------------

    private function form(?array $record, array $old, array $errors, int $status = 200): Response
    {
        $user = CurrentUser::user();
        $contactOptions = [];

        if ($record === null) {
            // Only contacts without a record yet can be picked.
            $taken = array_flip(array_map('intval', array_column($this->records->list($user, ['limit' => 1000]), 'contact_id')));
            foreach ($this->contacts->search([]) as $contact) {
                if (!isset($taken[(int) $contact['id']])) {
                    $contactOptions[] = $contact;
                }
            }
        }

        return $this->viewWithLayout('client-records/form', 'layout', [
            'record' => $record,
            'contact' => $record !== null ? $this->records->contactFor($record) : null,
            'contactOptions' => $contactOptions,
            'users' => $this->records->organizationUserNames($user),
            'canSensitive' => $record === null || $this->records->canViewSensitive($user, $record),
            'customFieldDefs' => $this->records->customFieldDefinitions(),
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    /** Header/overview numbers derived from rows already loaded for the page. */
    private function summary(array $services, array $sessions, array $invoices): array
    {
        $now = date('Y-m-d H:i:s');
        $lastVisit = null;
        $nextVisit = null;
        $completed = 0;

        foreach ($sessions as $session) {
            if ($session['status'] === 'completed') {
                $completed++;
                if ($session['session_at'] <= $now && ($lastVisit === null || $session['session_at'] > $lastVisit['session_at'])) {
                    $lastVisit = $session;
                }
            }
            if ($session['status'] === 'scheduled' && $session['session_at'] >= $now
                && ($nextVisit === null || $session['session_at'] < $nextVisit['session_at'])) {
                $nextVisit = $session;
            }
        }

        // Outstanding balance per currency, from issued invoices only —
        // amounts are never summed across currencies (Phase 0 §48). Finance's
        // rule: grand − credited − paid (credited_total comes with Finance's
        // invoice list; finance remediation P2).
        $outstanding = [];
        foreach ($invoices as $invoice) {
            if ($invoice['status'] !== 'issued') {
                continue;
            }
            $due = bcsub(bcsub((string) $invoice['grand_total'], (string) ($invoice['credited_total'] ?? '0'), 18), (string) $invoice['paid_total'], 18);
            if (bccomp($due, '0', 18) > 0) {
                $code = (string) ($invoice['currency_code'] ?? '');
                $outstanding[$code] = bcadd($outstanding[$code] ?? '0', $due, 18);
            }
        }

        return [
            'last_visit' => $lastVisit,
            'next_visit' => $nextVisit,
            'services_count' => count($services),
            'active_services' => count(array_filter($services, fn ($s) => in_array($s['status'], ['planned', 'in_progress'], true))),
            'sessions_completed' => $completed,
            'invoices_count' => count($invoices),
            'outstanding' => $outstanding,
            'latest_service' => $services[0] ?? null,
        ];
    }

    private function firstError(ValidationException $e): string
    {
        return (string) (array_values($e->errors)[0][0] ?? '');
    }
}
