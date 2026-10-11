<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\ClientRecords\Services\ClientIntegrations;
use Modules\ClientRecords\Services\ClientRecordService;
use Modules\ClientRecords\Services\ClientTreatmentService;

use function App\Infrastructure\Theme\t;

/** Create/edit/delete of service-history rows and sessions under one record. */
final class ClientTreatmentController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ClientRecordService $records,
        private readonly ClientTreatmentService $treatment,
        private readonly ClientIntegrations $integrations,
    ) {
        parent::__construct($templates);
    }

    // ---- Services --------------------------------------------------

    public function showCreateService(Request $request): Response
    {
        $record = $this->editableRecord($request);

        return $this->serviceForm($record, null, ['status' => 'planned'], []);
    }

    public function createService(Request $request): Response
    {
        $record = $this->editableRecord($request);

        try {
            $this->treatment->createService(CurrentUser::user(), $record, $request->all());
        } catch (ValidationException $e) {
            return $this->serviceForm($record, null, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('client_records.flash.service_saved'));

        return $this->redirect($this->tabUrl($record, 'services'));
    }

    public function showEditService(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $service = $this->treatment->findService($record, (int) $request->attribute('serviceId'));

        return $this->serviceForm($record, $service, $service, []);
    }

    public function updateService(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $serviceId = (int) $request->attribute('serviceId');

        try {
            $this->treatment->updateService(CurrentUser::user(), $record, $serviceId, $request->all());
        } catch (ValidationException $e) {
            return $this->serviceForm($record, $this->treatment->findService($record, $serviceId), $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('client_records.flash.service_saved'));

        return $this->redirect($this->tabUrl($record, 'services'));
    }

    public function deleteService(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $this->treatment->deleteService(CurrentUser::user(), $record, (int) $request->attribute('serviceId'));
        Session::setFlash('success', t('client_records.flash.service_deleted'));

        return $this->redirect($this->tabUrl($record, 'services'));
    }

    // ---- Sessions --------------------------------------------------

    public function showCreateSession(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $serviceId = (int) $request->query('service_id', 0) ?: null;
        $scheduled = $request->query('status') === 'scheduled';

        $old = [
            'service_record_id' => $serviceId,
            'session_number' => $this->treatment->nextSessionNumber($record, $serviceId),
            'status' => $scheduled ? 'scheduled' : 'completed',
            'session_date' => date('Y-m-d'),
            'session_time' => $scheduled ? '' : date('H:i'),
            'provider_user_id' => $record['assigned_user_id'] ?? CurrentUser::id(),
        ];

        return $this->sessionForm($record, null, $old, []);
    }

    public function createSession(Request $request): Response
    {
        $record = $this->editableRecord($request);

        try {
            $session = $this->treatment->createSession(CurrentUser::user(), $record, $request->all());
        } catch (ValidationException $e) {
            return $this->sessionForm($record, null, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('client_records.flash.session_saved'));

        return $this->redirect($this->tabUrl($record, $session['status'] === 'scheduled' ? 'appointments' : 'sessions'));
    }

    public function showEditSession(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $session = $this->treatment->findSession(CurrentUser::user(), $record, (int) $request->attribute('sessionId'));

        $old = $session;
        $old['session_date'] = substr((string) $session['session_at'], 0, 10);
        $old['session_time'] = substr((string) $session['session_at'], 11, 5);

        return $this->sessionForm($record, $session, $old, []);
    }

    public function updateSession(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $sessionId = (int) $request->attribute('sessionId');

        try {
            $this->treatment->updateSession(CurrentUser::user(), $record, $sessionId, $request->all());
        } catch (ValidationException $e) {
            return $this->sessionForm($record, $this->treatment->findSession(CurrentUser::user(), $record, $sessionId), $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('client_records.flash.session_saved'));

        return $this->redirect($this->tabUrl($record, 'sessions'));
    }

    public function changeSessionStatus(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $status = (string) $request->input('status', '');

        try {
            $this->treatment->changeSessionStatus(CurrentUser::user(), $record, (int) $request->attribute('sessionId'), $status);
            Session::setFlash('success', t('client_records.flash.session_status', ['status' => t('client_records.session_status.' . $status)]));
        } catch (ValidationException) {
            Session::setFlash('error', t('client_records.validation.invalid_status'));
        }

        return $this->redirect($this->tabUrl($record, 'appointments'));
    }

    public function deleteSession(Request $request): Response
    {
        $record = $this->editableRecord($request);
        $this->treatment->deleteSession(CurrentUser::user(), $record, (int) $request->attribute('sessionId'));
        Session::setFlash('success', t('client_records.flash.session_deleted'));

        return $this->redirect($this->tabUrl($record, 'sessions'));
    }

    // ---------------------------------------------------------------

    /** Loads the record (tenant + view check) and requires manage_sessions on it before any form is shown. */
    private function editableRecord(Request $request): array
    {
        $user = CurrentUser::user();
        $record = $this->records->find($user, (int) $request->attribute('id'));
        $this->records->authorizeRecord($user, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);

        return $record;
    }

    private function serviceForm(array $record, ?array $service, array $old, array $errors, int $status = 200): Response
    {
        $user = CurrentUser::user();

        return $this->viewWithLayout('client-records/service-form', 'layout', [
            'record' => $record,
            'contact' => $this->records->contactFor($record),
            'service' => $service,
            'products' => $this->integrations->activeProducts($user),
            'invoices' => $this->integrations->invoicesForContact($user, (int) $record['contact_id']),
            'users' => $this->records->organizationUserNames($user),
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    private function sessionForm(array $record, ?array $session, array $old, array $errors, int $status = 200): Response
    {
        $user = CurrentUser::user();

        return $this->viewWithLayout('client-records/session-form', 'layout', [
            'record' => $record,
            'contact' => $this->records->contactFor($record),
            'session' => $session,
            'services' => $this->treatment->servicesFor($record),
            'invoices' => $this->integrations->invoicesForContact($user, (int) $record['contact_id']),
            'users' => $this->records->organizationUserNames($user),
            'canSensitive' => $this->records->canViewSensitive($user, $record),
            'canFollowUp' => $this->integrations->canCreateFollowUp($user) && ($session === null || $session['follow_up_id'] === null),
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    private function tabUrl(array $record, string $tab): string
    {
        return '/client-records/' . (int) $record['id'] . '?tab=' . $tab;
    }
}
