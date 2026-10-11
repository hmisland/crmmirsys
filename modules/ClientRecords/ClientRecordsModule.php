<?php
/**
 * CRM GROUP by MIRSYS — crmgroup.ir
 * Copyright (c) 2026 MIRSYS. All rights reserved.
 */

declare(strict_types=1);

namespace Modules\ClientRecords;

use App\Application\Services\AttachmentService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\NoteService;
use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\ClientRecords\Controllers\Api\ClientRecordApiController;
use Modules\ClientRecords\Controllers\ClientFileController;
use Modules\ClientRecords\Controllers\ClientRecordController;
use Modules\ClientRecords\Controllers\ClientTreatmentController;
use Modules\ClientRecords\Services\ClientFileService;
use Modules\ClientRecords\Services\ClientIntegrations;
use Modules\ClientRecords\Services\ClientRecordService;
use Modules\ClientRecords\Services\ClientTimelineService;
use Modules\ClientRecords\Services\ClientTreatmentService;

/**
 * Client Records (سوابق مخاطب): a structured history layer around the
 * existing Contact — intake, service history, sessions, before/after
 * media, documents and a unified timeline — for service businesses
 * (clinics, salons, physio, consulting...). Not an EMR.
 *
 * Reuses, never duplicates: contacts, users, RBAC, module entitlement,
 * Core attachments/notes/custom fields/timeline, Finance products/
 * invoices/payments and the Follow-ups engine (the last two optional,
 * via ClientIntegrations). See docs/client-records-module.md.
 */
final class ClientRecordsModule implements ModuleInterface
{
    private ?ClientRecordController $recordController = null;
    private ?ClientTreatmentController $treatmentController = null;
    private ?ClientFileController $fileController = null;
    private ?ClientRecordApiController $apiController = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);
        $templates = $container->make(TemplateEngine::class);
        $timeline = $container->make(TimelineService::class);
        $attachments = $container->make(AttachmentService::class);

        $recordRepo = new ClientRecordRepository();
        $serviceRepo = new ServiceRecordRepository();
        $sessionRepo = new SessionRepository();
        $fileRepo = new ClientFileRepository();
        $contacts = new ContactRepository();

        $integrations = new ClientIntegrations($container, $authorization);

        $recordService = new ClientRecordService(
            $recordRepo,
            $contacts,
            new UserRepository(),
            $fileRepo,
            $attachments,
            $container->make(NoteService::class),
            $container->make(CustomFieldService::class),
            $timeline,
            $authorization,
            $container->make(LoggerInterface::class),
        );
        $treatmentService = new ClientTreatmentService($serviceRepo, $sessionRepo, $recordService, $integrations, $timeline);
        $fileService = new ClientFileService($fileRepo, $serviceRepo, $sessionRepo, $attachments, $recordService, $timeline);
        $timelineService = new ClientTimelineService($timeline, $integrations, $recordService);

        // Bound for optional, lazy consumers in Core (global search, the
        // contact page link) — they check has() first, so Core works the
        // same whether this module is installed or not.
        $container->singleton(ClientRecordService::class, fn () => $recordService);

        $this->recordController = new ClientRecordController(
            $templates, $recordService, $treatmentService, $fileService, $timelineService, $integrations, $contacts, $authorization
        );
        $this->treatmentController = new ClientTreatmentController($templates, $recordService, $treatmentService, $integrations);
        $this->fileController = new ClientFileController($templates, $recordService, $fileService);
        $this->apiController = new ClientRecordApiController($recordService, $treatmentService, $fileService, $timelineService, $integrations);
    }

    public function routes(Router $router): void
    {
        // Entitlement (module) and permission (RBAC) are separate gates;
        // both must pass. Services re-check the permission on the record.
        $gate = [new AuthMiddleware(), new ModuleMiddleware('client_records'), new RequireOrganizationMiddleware()];
        $view = new RbacMiddleware('client_records.view');
        $csrf = new CsrfMiddleware();

        $records = $this->recordController;
        $treatment = $this->treatmentController;
        $files = $this->fileController;
        $api = $this->apiController;

        $router->group('/client-records', $gate, function (Router $router) use ($records, $treatment, $files, $view, $csrf) {
            $router->get('', fn (Request $r) => $records->index($r), [$view]);
            $router->post('', fn (Request $r) => $records->create($r), [new RbacMiddleware('client_records.create'), $csrf]);
            $router->get('/create', fn (Request $r) => $records->showCreate($r), [new RbacMiddleware('client_records.create')]);
            $router->get('/export', fn (Request $r) => $records->export($r), [new RbacMiddleware('client_records.export')]);
            $router->get('/for-contact/{contactId}', fn (Request $r) => $records->forContact($r), [$view]);

            $router->post('/bulk-delete', fn (Request $r) => $records->bulkDelete($r), [new RbacMiddleware('client_records.delete'), $csrf]);
            $router->get('/{id}', fn (Request $r) => $records->show($r), [$view]);
            $router->post('/{id}', fn (Request $r) => $records->update($r), [$view, $csrf]);
            $router->get('/{id}/edit', fn (Request $r) => $records->showEdit($r), [$view]);
            $router->post('/{id}/status', fn (Request $r) => $records->changeStatus($r), [$view, $csrf]);
            $router->post('/{id}/delete', fn (Request $r) => $records->delete($r), [new RbacMiddleware('client_records.delete'), $csrf]);
            $router->post('/{id}/notes', fn (Request $r) => $records->addNote($r), [$view, $csrf]);
            $router->post('/{id}/notes/{noteId}/delete', fn (Request $r) => $records->deleteNote($r), [$view, $csrf]);
            $router->post('/{id}/followups', fn (Request $r) => $records->createFollowUp($r), [$view, $csrf]);

            $router->get('/{id}/services/create', fn (Request $r) => $treatment->showCreateService($r), [$view]);
            $router->post('/{id}/services', fn (Request $r) => $treatment->createService($r), [$view, $csrf]);
            $router->get('/{id}/services/{serviceId}/edit', fn (Request $r) => $treatment->showEditService($r), [$view]);
            $router->post('/{id}/services/{serviceId}', fn (Request $r) => $treatment->updateService($r), [$view, $csrf]);
            $router->post('/{id}/services/{serviceId}/delete', fn (Request $r) => $treatment->deleteService($r), [$view, $csrf]);

            $router->get('/{id}/sessions/create', fn (Request $r) => $treatment->showCreateSession($r), [$view]);
            $router->post('/{id}/sessions', fn (Request $r) => $treatment->createSession($r), [$view, $csrf]);
            $router->get('/{id}/sessions/{sessionId}/edit', fn (Request $r) => $treatment->showEditSession($r), [$view]);
            $router->post('/{id}/sessions/{sessionId}', fn (Request $r) => $treatment->updateSession($r), [$view, $csrf]);
            $router->post('/{id}/sessions/{sessionId}/status', fn (Request $r) => $treatment->changeSessionStatus($r), [$view, $csrf]);
            $router->post('/{id}/sessions/{sessionId}/delete', fn (Request $r) => $treatment->deleteSession($r), [$view, $csrf]);

            $router->post('/{id}/files', fn (Request $r) => $files->upload($r), [$view, $csrf]);
            $router->get('/{id}/files/{fileId}', fn (Request $r) => $files->show($r), [$view]);
            $router->post('/{id}/files/{fileId}/delete', fn (Request $r) => $files->delete($r), [$view, $csrf]);
        });

        $router->group('/api/v1/client-records', $gate, function (Router $router) use ($api, $view, $csrf) {
            $router->get('', fn (Request $r) => $api->index($r), [$view]);
            $router->post('', fn (Request $r) => $api->create($r), [new RbacMiddleware('client_records.create'), $csrf]);
            $router->get('/{id}', fn (Request $r) => $api->show($r), [$view]);
            $router->patch('/{id}', fn (Request $r) => $api->update($r), [$view, $csrf]);
            $router->get('/{id}/services', fn (Request $r) => $api->services($r), [$view]);
            $router->post('/{id}/services', fn (Request $r) => $api->createService($r), [$view, $csrf]);
            $router->get('/{id}/sessions', fn (Request $r) => $api->sessions($r), [$view]);
            $router->post('/{id}/sessions', fn (Request $r) => $api->createSession($r), [$view, $csrf]);
            $router->get('/{id}/files', fn (Request $r) => $api->files($r), [$view]);
            $router->get('/{id}/timeline', fn (Request $r) => $api->timeline($r), [$view]);
            $router->get('/{id}/followups', fn (Request $r) => $api->followUps($r), [$view]);
            $router->get('/{id}/financials', fn (Request $r) => $api->financials($r), [$view]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
