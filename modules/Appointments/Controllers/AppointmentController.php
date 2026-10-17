<?php

declare(strict_types=1);

namespace Modules\Appointments\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AppointmentSettingsService;
use Modules\Appointments\Services\AvailabilityService;
use Modules\Appointments\Services\CalendarService;

use function App\Infrastructure\Theme\t;

/**
 * Calendar (day / week), list, and the appointment form and page.
 * Request/response plumbing only; every rule lives in the services.
 */
final class AppointmentController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly AppointmentService $appointments,
        private readonly AppointmentSettingsService $settings,
        private readonly CalendarService $calendar,
    ) {
        parent::__construct($templates);
    }

    public function calendar(Request $request): Response
    {
        $user = CurrentUser::user();
        $view = $request->query('view') === 'week' ? 'week' : 'day';
        $date = $this->dateOr($request->query('date'), \App\Infrastructure\Time\OrganizationTimezone::shared()->today());
        $resourceId = (int) $request->query('resource_id', 0) ?: null;
        $locale = $this->locale();

        $resources = $this->settings->resources(true);
        $shown = $resourceId === null ? $resources : array_values(array_filter($resources, fn ($r) => (int) $r['id'] === $resourceId));

        if ($view === 'week') {
            $from = AvailabilityService::weekStart($date, $locale);
            $to = date('Y-m-d', strtotime($from . ' +7 days'));
            $prev = date('Y-m-d', strtotime($from . ' -7 days'));
            $next = $to;
        } else {
            $from = $date;
            $to = date('Y-m-d', strtotime($date . ' +1 day'));
            $prev = date('Y-m-d', strtotime($date . ' -1 day'));
            $next = $to;
        }

        $rows = $this->appointments->list($user, [
            'from' => $from . ' 00:00:00',
            'to' => $to . ' 00:00:00',
            'resource_id' => $resourceId ?? '',
            'limit' => 2000,
        ]);

        return $this->viewWithLayout('appointments/calendar', 'layout', [
            'view' => $view,
            'date' => $date,
            'prev' => $prev,
            'next' => $next,
            'resourceId' => $resourceId,
            'resources' => $resources,
            'grid' => $view === 'day' ? $this->calendar->dayGrid($date, $shown, $rows) : null,
            'week' => $view === 'week' ? $this->calendar->week($from, $rows) : null,
            'fmt' => new LocaleFormatter($locale),
            'canCreate' => $this->appointments->can($user, Permission::APPOINTMENTS_CREATE),
            'canManage' => $this->settings->canManage($user),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = [
            'from' => $this->dateOr($request->query('from'), ''),
            'to' => $this->dateOr($request->query('to'), ''),
            'status' => in_array($request->query('status'), AppointmentService::STATUSES, true) ? (string) $request->query('status') : '',
            'resource_id' => (int) $request->query('resource_id', 0) ?: '',
            'assigned_user_id' => (int) $request->query('assigned_user_id', 0) ?: '',
            'q' => trim((string) $request->query('q', '')),
        ];

        $rows = $this->appointments->list($user, [
            'from' => $filters['from'] !== '' ? $filters['from'] . ' 00:00:00' : '',
            'to' => $filters['to'] !== '' ? date('Y-m-d', strtotime($filters['to'] . ' +1 day')) . ' 00:00:00' : '',
            'status' => $filters['status'],
            'resource_id' => $filters['resource_id'],
            'assigned_user_id' => $filters['assigned_user_id'],
            'q' => $filters['q'],
            'newest_first' => $filters['from'] === '' && $filters['to'] === '',
        ]);

        [$rows, $pagination] = Pagination::slice($rows, $request);

        return $this->viewWithLayout('appointments/list', 'layout', [
            'rows' => $rows,
            'pagination' => $pagination,
            'filters' => $filters,
            'resources' => $this->settings->resources(),
            'users' => $this->appointments->userNames($user),
            'fmt' => new LocaleFormatter($this->locale()),
            'canCreate' => $this->appointments->can($user, Permission::APPOINTMENTS_CREATE),
        ]);
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $appointment = $this->appointments->find($user, (int) $request->attribute('id'));

        return $this->viewWithLayout('appointments/show', 'layout', [
            'appointment' => $appointment,
            'users' => $this->appointments->userNames($user),
            'fmt' => new LocaleFormatter($this->locale()),
            'canEdit' => $this->appointments->can($user, Permission::APPOINTMENTS_EDIT, $appointment),
            'canDelete' => $this->appointments->can($user, Permission::APPOINTMENTS_DELETE, $appointment),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        $old = [
            'contact_id' => (int) $request->query('contact_id', 0) ?: '',
            'resource_id' => (int) $request->query('resource_id', 0) ?: '',
            'appointment_type_id' => (int) $request->query('appointment_type_id', 0) ?: '',
            'date' => $this->dateOr($request->query('date'), \App\Infrastructure\Time\OrganizationTimezone::shared()->today()),
            'start_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $request->query('time', '')) ? (string) $request->query('time') : '',
            'status' => 'scheduled',
        ];

        return $this->form(null, $old, []);
    }

    public function create(Request $request): Response
    {
        try {
            $appointment = $this->appointments->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->form(null, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('appointments.flash.saved'));

        return $this->redirect('/appointments/' . (int) $appointment['id']);
    }

    public function showEdit(Request $request): Response
    {
        $appointment = $this->editable($request);

        $old = $appointment;
        $old['date'] = substr((string) $appointment['starts_at'], 0, 10);
        $old['start_time'] = substr((string) $appointment['starts_at'], 11, 5);
        $old['duration_minutes'] = (string) intdiv(strtotime((string) $appointment['ends_at']) - strtotime((string) $appointment['starts_at']), 60);

        return $this->form($appointment, $old, []);
    }

    public function update(Request $request): Response
    {
        $appointment = $this->editable($request);

        try {
            $this->appointments->update(CurrentUser::user(), (int) $appointment['id'], $request->all());
        } catch (ValidationException $e) {
            return $this->form($appointment, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('appointments.flash.saved'));

        return $this->redirect('/appointments/' . (int) $appointment['id']);
    }

    public function changeStatus(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $status = (string) $request->input('status', '');

        try {
            $this->appointments->changeStatus(CurrentUser::user(), $id, $status, (string) $request->input('cancel_reason', ''));
            Session::setFlash('success', t('appointments.flash.status_changed', ['status' => t('appointments.status.' . $status)]));
        } catch (ValidationException $e) {
            $first = array_values($e->errors)[0][0] ?? 'appointments.validation.invalid_status';
            Session::setFlash('error', t($first));
        }

        return $this->redirect($this->backTo($request, '/appointments/' . $id));
    }

    public function delete(Request $request): Response
    {
        $this->appointments->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('appointments.flash.deleted'));

        return $this->redirect('/appointments');
    }

    // ---------------------------------------------------------------

    private function editable(Request $request): array
    {
        $user = CurrentUser::user();
        $appointment = $this->appointments->find($user, (int) $request->attribute('id'));

        if (!$this->appointments->can($user, Permission::APPOINTMENTS_EDIT, $appointment)) {
            throw new AuthorizationException();
        }

        return $appointment;
    }

    private function form(?array $appointment, array $old, array $errors, int $status = 200): Response
    {
        $user = CurrentUser::user();

        return $this->viewWithLayout('appointments/form', 'layout', [
            'appointment' => $appointment,
            'contacts' => $this->appointments->contactOptions(),
            'resources' => $this->settings->resources(true),
            'types' => $this->settings->types(true),
            'users' => $this->appointments->userNames($user),
            'canManage' => $this->settings->canManage($user),
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    /** Only same-site relative paths are accepted as a return target. */
    private function backTo(Request $request, string $fallback): string
    {
        $back = (string) $request->input('return_to', '');

        return preg_match('#^/appointments(?:[/?][^\s]*)?$#', $back) && !str_starts_with($back, '//') ? $back : $fallback;
    }

    private function dateOr(mixed $raw, string $fallback): string
    {
        $value = trim((string) ($raw ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : $fallback;
    }

    private function locale(): string
    {
        $translator = Locale::get();

        return $translator !== null ? $translator->locale() : 'en';
    }
}
