<?php
/**
 * CRM GROUP by MIRSYS — crmgroup.ir
 * Copyright (c) 2026 MIRSYS. All rights reserved.
 */

declare(strict_types=1);

namespace Modules\Appointments;

use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Appointments\Controllers\AppointmentController;
use Modules\Appointments\Controllers\AppointmentSettingsController;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AppointmentSettingsService;
use Modules\Appointments\Services\AvailabilityService;
use Modules\Appointments\Services\CalendarService;

/**
 * Appointments (قرار ملاقات): staff book time for a contact on a bookable
 * resource — a person, a place or a piece of equipment — with types
 * (default length + buffer), weekly working hours, capacity, conflict
 * checks, and day / week / list views. Industry-neutral.
 *
 * Staff-operated in this version. The schema (appointments.source,
 * status `requested`, appointment_types.is_public) and the separate
 * AvailabilityService are there so a public booking page or customer
 * portal can be added later on top of the same rules.
 * See docs/appointments-module.md.
 */
final class AppointmentsModule implements ModuleInterface
{
    private ?AppointmentController $appointmentController = null;
    private ?AppointmentSettingsController $settingsController = null;
    private ?AppointmentService $appointmentServiceInstance = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** For optional consumers in Core (e.g. a contact's upcoming appointments) without a hard dependency. */
    public function appointmentService(): ?AppointmentService
    {
        return $this->appointmentServiceInstance;
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);
        $templates = $container->make(TemplateEngine::class);

        $appointmentRepo = new AppointmentRepository();
        $resourceRepo = new ResourceRepository();
        $typeRepo = new AppointmentTypeRepository();
        $hoursRepo = new WorkingHoursRepository();
        $users = new UserRepository();

        $availability = new AvailabilityService($hoursRepo, $appointmentRepo);
        $appointments = new AppointmentService(
            $appointmentRepo,
            $resourceRepo,
            $typeRepo,
            $availability,
            new ContactRepository(),
            $users,
            $container->make(TimelineService::class),
            $authorization,
        );
        $settings = new AppointmentSettingsService($resourceRepo, $typeRepo, $hoursRepo, $appointmentRepo, $users, $authorization);

        $this->appointmentServiceInstance = $appointments;
        $container->singleton(AvailabilityService::class, fn () => $availability);

        $this->appointmentController = new AppointmentController($templates, $appointments, $settings, new CalendarService($availability));
        $this->settingsController = new AppointmentSettingsController($templates, $settings, $appointments);
    }

    public function routes(Router $router): void
    {
        // Entitlement (module) and permission (RBAC) are separate gates;
        // both must pass. Services re-check the permission on the record.
        $gate = [new AuthMiddleware(), new ModuleMiddleware('appointments'), new RequireOrganizationMiddleware()];
        $view = new RbacMiddleware('appointments.view');
        $manage = new RbacMiddleware('appointments.manage_settings');
        $csrf = new CsrfMiddleware();

        $appointments = $this->appointmentController;
        $settings = $this->settingsController;

        $router->group('/appointments', $gate, function (Router $router) use ($appointments, $settings, $view, $manage, $csrf) {
            $router->get('', fn (Request $r) => $appointments->calendar($r), [$view]);
            $router->get('/list', fn (Request $r) => $appointments->index($r), [$view]);
            $router->get('/create', fn (Request $r) => $appointments->showCreate($r), [new RbacMiddleware('appointments.create')]);
            $router->post('', fn (Request $r) => $appointments->create($r), [new RbacMiddleware('appointments.create'), $csrf]);

            $router->get('/settings', fn (Request $r) => $settings->index($r), [$manage]);
            $router->get('/settings/resources/create', fn (Request $r) => $settings->showCreateResource($r), [$manage]);
            $router->post('/settings/resources', fn (Request $r) => $settings->createResource($r), [$manage, $csrf]);
            $router->get('/settings/resources/{id}/edit', fn (Request $r) => $settings->showEditResource($r), [$manage]);
            $router->post('/settings/resources/{id}', fn (Request $r) => $settings->updateResource($r), [$manage, $csrf]);
            $router->post('/settings/resources/{id}/delete', fn (Request $r) => $settings->deleteResource($r), [$manage, $csrf]);
            $router->get('/settings/types/create', fn (Request $r) => $settings->showCreateType($r), [$manage]);
            $router->post('/settings/types', fn (Request $r) => $settings->createType($r), [$manage, $csrf]);
            $router->get('/settings/types/{id}/edit', fn (Request $r) => $settings->showEditType($r), [$manage]);
            $router->post('/settings/types/{id}', fn (Request $r) => $settings->updateType($r), [$manage, $csrf]);
            $router->post('/settings/types/{id}/delete', fn (Request $r) => $settings->deleteType($r), [$manage, $csrf]);

            $router->get('/{id}', fn (Request $r) => $appointments->show($r), [$view]);
            $router->post('/{id}', fn (Request $r) => $appointments->update($r), [$view, $csrf]);
            $router->get('/{id}/edit', fn (Request $r) => $appointments->showEdit($r), [$view]);
            $router->post('/{id}/status', fn (Request $r) => $appointments->changeStatus($r), [$view, $csrf]);
            $router->post('/{id}/delete', fn (Request $r) => $appointments->delete($r), [new RbacMiddleware('appointments.delete'), $csrf]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
