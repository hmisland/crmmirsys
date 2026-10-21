<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Events\EventBus;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use App\Infrastructure\Time\OrganizationTimezone;
use App\Infrastructure\Mail\MailerInterface;
use Modules\Sales\Controllers\DealController;
use Modules\Sales\Controllers\PipelineController;
use Modules\Sales\Controllers\TaskController;
use Modules\Sales\Services\DealService;
use Modules\Sales\Services\PipelineService;
use Modules\Sales\Services\TaskLabelService;
use Modules\Sales\Services\TaskRelatedRecords;
use Modules\Sales\Services\TaskRecurrenceService;
use Modules\Sales\Services\TaskReminderService;
use Modules\Sales\Services\TaskService;

final class SalesModule implements ModuleInterface, ProvidesServices
{
    private ?DealController $dealController = null;
    private ?PipelineController $pipelineController = null;
    private ?TaskController $taskController = null;
    private ?TaskService $taskServiceInstance = null;
    private ?TaskReminderService $reminderService = null;
    private ?TaskRecurrenceService $recurrenceService = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Core (the Dashboard) can optionally read the user's own tasks without hard-depending on this module — same lazy pattern as Notification/Wallet. */
    public function taskService(): ?TaskService
    {
        return $this->taskServiceInstance;
    }

    public function register(Container $container): void
    {
        $pipelineService = new PipelineService(
            new PipelineRepository(),
            new PipelineStageRepository(),
            $container->make(AuthorizationEngine::class)
        );

        $deals = new DealRepository();

        // Sales' own record type, so other modules can link to / look up a
        // deal through Core (RecordTypeRegistry::find()) without importing
        // Sales. Sales knows nothing about who uses it.
        $container->make(RecordTypeRegistry::class)->register(new RecordType(
            key: 'deal',
            module: 'sales',
            viewPermission: Permission::DEALS_VIEW,
            labelKey: 'nav.deals',
            showPath: '/deals/{id}',
            icon: 'briefcase',
            finder: fn (int $id): ?array => $deals->findById($id),
            recordSupport: false, // deals keep their own notes; /records/deal/... stays 404 as before
        ));

        $dealService = new DealService(
            $deals,
            $pipelineService,
            $container->make(TimelineService::class),
            $container->make(AuthorizationEngine::class),
            $container->make(EventBus::class)
        );

        $taskService = new TaskService(
            new TaskRepository(),
            $container->make(NoteService::class),
            $container->make(AttachmentService::class),
            $container->make(AuthorizationEngine::class),
            new UserRepository(),
            new TaskChecklistRepository(),
            new TaskRelatedRecords(
                $container->make(RecordTypeRegistry::class),
                $container->make(AuthorizationEngine::class),
                new ContactRepository(),
                new CompanyRepository(),
                new LeadRepository(),
                $deals
            ),
            new TaskLabelRepository()
        );
        $taskLabelService = new TaskLabelService(new TaskLabelRepository(), $container->make(AuthorizationEngine::class));
        $this->taskServiceInstance = $taskService;
        $this->reminderService = new TaskReminderService(
            new TaskNotificationRepository(),
            new TaskRepository(),
            $taskService,
            new UserRepository(),
            new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository()),
            OrganizationTimezone::shared(),
            $container->make(MailerInterface::class)
        );
        $this->recurrenceService = new TaskRecurrenceService(
            new TaskRecurrenceRepository(),
            new TaskRepository(),
            $taskService,
            new UserRepository(),
            OrganizationTimezone::shared()
        );

        $templates = $container->make(TemplateEngine::class);

        $this->dealController = new DealController(
            $templates,
            $dealService,
            $pipelineService,
            $taskService,
            new ContactRepository(),
            new CompanyRepository(),
            new UserRepository()
        );

        $this->pipelineController = new PipelineController($templates, $pipelineService);
        $this->taskController = new TaskController($templates, $taskService, $taskLabelService, $this->reminderService);

        // "Tasks" on project pages — via the Core panel slot only (ProjectTaskPanels).
        (new ProjectTaskPanels($taskService, $templates))->registerAll($container->make(RelatedPanelRegistry::class));
    }

    /** The reminder/email and recurrence processors, for modules/Sales/bin/task-reminders.php (cron). */
    public function services(): array
    {
        return [TaskReminderService::class => $this->reminderService, TaskRecurrenceService::class => $this->recurrenceService];
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('sales');
        $dealController = $this->dealController;
        $pipelineController = $this->pipelineController;
        $taskController = $this->taskController;

        $router->group('/deals', [$auth, $module], function (Router $router) use ($dealController) {
            $router->get('', fn (Request $r) => $dealController->index($r), [new RbacMiddleware('deals.view')]);
            $router->get('/board', fn (Request $r) => $dealController->board($r));
            $router->get('/create', fn (Request $r) => $dealController->showCreate($r));
            $router->post('', fn (Request $r) => $dealController->create($r), [new CsrfMiddleware()]);
            $router->get('/{id}', fn (Request $r) => $dealController->show($r));
            $router->get('/{id}/edit', fn (Request $r) => $dealController->showEdit($r));
            $router->post('/{id}', fn (Request $r) => $dealController->update($r), [new CsrfMiddleware()]);
            $router->post('/{id}/stage', fn (Request $r) => $dealController->changeStage($r), [new CsrfMiddleware()]);
            $router->post('/{id}/delete', fn (Request $r) => $dealController->delete($r), [new CsrfMiddleware()]);
        });

        $router->group('/sales', [$auth, $module], function (Router $router) use ($pipelineController) {
            $router->get('/pipeline', fn (Request $r) => $pipelineController->index($r), [new RbacMiddleware('pipelines.manage')]);
            $router->post('/pipeline/stages', fn (Request $r) => $pipelineController->addStage($r), [new CsrfMiddleware()]);
        });

        $router->group('/tasks', [$auth, $module], function (Router $router) use ($taskController) {
            $router->get('', fn (Request $r) => $taskController->myTasks($r));
            // Labels before /{id}: "labels" would otherwise match {id} (first match wins).
            $router->get('/labels', fn (Request $r) => $taskController->labels($r));
            $router->post('/labels', fn (Request $r) => $taskController->createLabel($r), [new CsrfMiddleware()]);
            $router->post('/labels/{labelId}', fn (Request $r) => $taskController->updateLabel($r), [new CsrfMiddleware()]);
            $router->post('/labels/{labelId}/delete', fn (Request $r) => $taskController->deleteLabel($r), [new CsrfMiddleware()]);
            // Calendar (T9) and custom statuses (T8), before /{id}.
            $router->get('/calendar', fn (Request $r) => $taskController->calendar($r));
            $router->get('/statuses', fn (Request $r) => $taskController->statusesPage($r));
            $router->post('/statuses', fn (Request $r) => $taskController->createStatus($r), [new CsrfMiddleware()]);
            $router->post('/statuses/{statusId}', fn (Request $r) => $taskController->updateStatus($r), [new CsrfMiddleware()]);
            $router->post('/statuses/{statusId}/move', fn (Request $r) => $taskController->moveStatus($r), [new CsrfMiddleware()]);
            $router->post('/statuses/{statusId}/delete', fn (Request $r) => $taskController->deleteStatus($r), [new CsrfMiddleware()]);
            // Reminders inbox, also before /{id}.
            $router->get('/reminders', fn (Request $r) => $taskController->reminders($r));
            $router->post('/reminders/read-all', fn (Request $r) => $taskController->readAllReminders($r), [new CsrfMiddleware()]);
            $router->post('/reminders/{notificationId}/read', fn (Request $r) => $taskController->readReminder($r), [new CsrfMiddleware()]);
            $router->post('', fn (Request $r) => $taskController->create($r), [new CsrfMiddleware()]);
            $router->get('/{id}', fn (Request $r) => $taskController->show($r));
            $router->post('/{id}', fn (Request $r) => $taskController->update($r), [new CsrfMiddleware()]);
            $router->post('/{id}/toggle', fn (Request $r) => $taskController->toggleComplete($r), [new CsrfMiddleware()]);
            $router->post('/{id}/status', fn (Request $r) => $taskController->changeStatus($r), [new RbacMiddleware('tasks.change_status'), new CsrfMiddleware()]);
            $router->post('/{id}/archive', fn (Request $r) => $taskController->archive($r), [new RbacMiddleware('tasks.archive'), new CsrfMiddleware()]);
            $router->post('/{id}/unarchive', fn (Request $r) => $taskController->unarchive($r), [new RbacMiddleware('tasks.archive'), new CsrfMiddleware()]);
            $router->post('/{id}/delete', fn (Request $r) => $taskController->delete($r), [new CsrfMiddleware()]);
            $router->post('/{id}/comments', fn (Request $r) => $taskController->addComment($r), [new CsrfMiddleware()]);
            $router->post('/{id}/comments/{commentId}/delete', fn (Request $r) => $taskController->deleteComment($r), [new CsrfMiddleware()]);
            $router->post('/{id}/attachments', fn (Request $r) => $taskController->uploadAttachment($r), [new CsrfMiddleware()]);
            $router->get('/{id}/attachments/{attachmentId}/download', fn (Request $r) => $taskController->downloadAttachment($r));
            $router->post('/{id}/attachments/{attachmentId}/delete', fn (Request $r) => $taskController->deleteAttachment($r), [new CsrfMiddleware()]);
            $router->post('/{id}/labels', fn (Request $r) => $taskController->setLabels($r), [new CsrfMiddleware()]);
            $router->post('/{id}/checklist', fn (Request $r) => $taskController->addChecklistItem($r), [new CsrfMiddleware()]);
            $router->post('/{id}/checklist/{itemId}', fn (Request $r) => $taskController->renameChecklistItem($r), [new CsrfMiddleware()]);
            $router->post('/{id}/checklist/{itemId}/toggle', fn (Request $r) => $taskController->setChecklistItemDone($r), [new CsrfMiddleware()]);
            $router->post('/{id}/checklist/{itemId}/delete', fn (Request $r) => $taskController->deleteChecklistItem($r), [new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
