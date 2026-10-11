<?php

declare(strict_types=1);

namespace Modules\Appointments\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AppointmentSettingsService;
use Modules\Appointments\Services\AvailabilityService;

use function App\Infrastructure\Theme\t;

/** Resources (with working hours) and appointment types. All routes need appointments.manage_settings. */
final class AppointmentSettingsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly AppointmentSettingsService $settings,
        private readonly AppointmentService $appointments,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('appointments/settings', 'layout', [
            'resources' => $this->settings->resources(),
            'types' => $this->settings->types(),
            'users' => $this->appointments->userNames(CurrentUser::user()),
        ]);
    }

    // ---- Resources -------------------------------------------------

    public function showCreateResource(Request $request): Response
    {
        return $this->resourceForm(null, ['kind' => 'person', 'capacity' => '1', 'is_active' => '1', 'sort_order' => '0'], $this->settings->hoursForm(null), []);
    }

    public function createResource(Request $request): Response
    {
        try {
            $this->settings->createResource(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->resourceForm(null, $request->all(), $this->postedHours($request), $e->errors, 422);
        }

        Session::setFlash('success', t('appointments.flash.resource_saved'));

        return $this->redirect('/appointments/settings');
    }

    public function showEditResource(Request $request): Response
    {
        $resource = $this->settings->findResource((int) $request->attribute('id'));

        return $this->resourceForm($resource, $resource, $this->settings->hoursForm((int) $resource['id']), []);
    }

    public function updateResource(Request $request): Response
    {
        $resource = $this->settings->findResource((int) $request->attribute('id'));

        try {
            $this->settings->updateResource(CurrentUser::user(), (int) $resource['id'], $request->all());
        } catch (ValidationException $e) {
            return $this->resourceForm($resource, $request->all(), $this->postedHours($request), $e->errors, 422);
        }

        Session::setFlash('success', t('appointments.flash.resource_saved'));

        return $this->redirect('/appointments/settings');
    }

    public function deleteResource(Request $request): Response
    {
        try {
            $this->settings->deleteResource(CurrentUser::user(), (int) $request->attribute('id'));
            Session::setFlash('success', t('appointments.flash.resource_deleted'));
        } catch (ValidationException) {
            Session::setFlash('error', t('appointments.validation.resource_in_use'));
        }

        return $this->redirect('/appointments/settings');
    }

    // ---- Types -----------------------------------------------------

    public function showCreateType(Request $request): Response
    {
        return $this->typeForm(null, ['duration_minutes' => '30', 'buffer_minutes' => '0', 'is_active' => '1'], []);
    }

    public function createType(Request $request): Response
    {
        try {
            $this->settings->createType(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->typeForm(null, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('appointments.flash.type_saved'));

        return $this->redirect('/appointments/settings');
    }

    public function showEditType(Request $request): Response
    {
        $type = $this->settings->findType((int) $request->attribute('id'));

        return $this->typeForm($type, $type, []);
    }

    public function updateType(Request $request): Response
    {
        $type = $this->settings->findType((int) $request->attribute('id'));

        try {
            $this->settings->updateType(CurrentUser::user(), (int) $type['id'], $request->all());
        } catch (ValidationException $e) {
            return $this->typeForm($type, $request->all(), $e->errors, 422);
        }

        Session::setFlash('success', t('appointments.flash.type_saved'));

        return $this->redirect('/appointments/settings');
    }

    public function deleteType(Request $request): Response
    {
        try {
            $this->settings->deleteType(CurrentUser::user(), (int) $request->attribute('id'));
            Session::setFlash('success', t('appointments.flash.type_deleted'));
        } catch (ValidationException) {
            Session::setFlash('error', t('appointments.validation.type_in_use'));
        }

        return $this->redirect('/appointments/settings');
    }

    // ---------------------------------------------------------------

    private function resourceForm(?array $resource, array $old, array $hours, array $errors, int $status = 200): Response
    {
        $translator = Locale::get();

        return $this->viewWithLayout('appointments/resource-form', 'layout', [
            'resource' => $resource,
            'users' => $this->appointments->userNames(CurrentUser::user()),
            'hours' => $hours,
            'weekOrder' => AvailabilityService::weekOrder($translator !== null ? $translator->locale() : 'en'),
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    private function typeForm(?array $type, array $old, array $errors, int $status = 200): Response
    {
        return $this->viewWithLayout('appointments/type-form', 'layout', [
            'type' => $type,
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    /** Re-shows what was typed (padded like hoursForm()) after a failed save. */
    private function postedHours(Request $request): array
    {
        $raw = $request->input('hours', []);
        $raw = is_array($raw) ? $raw : [];
        $byDay = [];

        for ($day = 0; $day <= 6; $day++) {
            $ranges = [];
            for ($i = 0; $i < AppointmentSettingsService::RANGES_PER_DAY; $i++) {
                $ranges[] = [
                    'start' => (string) ($raw[$day][$i]['start'] ?? ''),
                    'end' => (string) ($raw[$day][$i]['end'] ?? ''),
                ];
            }
            $byDay[$day] = $ranges;
        }

        return $byDay;
    }
}
