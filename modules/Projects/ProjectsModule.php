<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
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
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\NumberSeriesRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use App\Infrastructure\Logging\LoggerInterface;
use Modules\Projects\Controllers\ProjectController;
use Modules\Projects\Controllers\ProjectSettingsController;
use Modules\Projects\Listeners\DealStageChangedListener;
use Modules\Projects\Services\ProjectService;

/**
 * Projects — an independently sellable module (docs/projects-module-design.md).
 * Runs with Core alone: it depends on no other module and imports none.
 * Everything it adds to the rest of the app goes through the Core
 * extension points (docs/module-extension-points.md).
 */
final class ProjectsModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'projects';
    public const RECORD_TYPE = 'project';

    /**
     * The one place another module is named — as a string, never imported
     * (decision G2, same as Contracts). The architecture test allows exactly
     * this string, here only. If Sales renames the event, ProjectDealEventTest fails.
     */
    public const DEAL_STAGE_CHANGED_EVENT = 'Modules\\Sales\\Events\\DealStageChanged';

    private ?ProjectService $projectService = null;
    private ?ProjectController $projectController = null;
    private ?ProjectSettingsController $settingsController = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        // Own keys under the "projects." prefix — Core doesn't list them.
        Permission::registerModuleKeys(self::KEY, ProjectPermission::ALL);

        $projects = new ProjectRepository();
        $links = new ProjectLinkRepository();

        // Core services and repositories only.
        $this->projectService = new ProjectService(
            $projects,
            new ProjectMemberRepository(),
            new NumberSeriesRepository(),
            $container->make(AuthorizationEngine::class),
            $container->make(TimelineService::class),
            $container->make(EventBus::class),
            new ContactRepository(),
            new CompanyRepository(),
            new UserRepository(),
            $links,
            $container->make(RecordTypeRegistry::class),
        );

        // Deal → Project: subscribed by class NAME only — no import of Sales.
        // Never fires when Sales isn't installed.
        $container->make(EventBus::class)->listen(self::DEAL_STAGE_CHANGED_EVENT, new DealStageChangedListener(
            $links,
            $container->make(TimelineService::class),
            $container->make(LoggerInterface::class),
            $this->projectService,
        ));

        $templates = $container->make(TemplateEngine::class);
        $this->projectController = new ProjectController(
            $templates,
            $this->projectService,
            $container->make(NoteService::class),
            $container->make(TagService::class),
            $container->make(TimelineService::class),
            $container->make(AttachmentService::class),
            new ContactRepository(),
            new CompanyRepository(),
        );
        $this->settingsController = new ProjectSettingsController($templates, $this->projectService);

        // "Projects" boxes on contact, company, deal and contract pages — via
        // the Core panel slot only; ProjectPanels explains the access rules.
        (new ProjectPanels($this->projectService, $templates))->registerAll($container->make(RelatedPanelRegistry::class));

        // Notes, tags, attachments and timeline on a project, and other
        // modules' lookups, all go through this registration (ModuleGate:
        // Projects available to the user + projects.view + record scope).
        $container->make(RecordTypeRegistry::class)->register(new RecordType(
            key: self::RECORD_TYPE,
            module: self::KEY,
            viewPermission: ProjectPermission::VIEW,
            labelKey: 'projects.record',
            showPath: '/projects/{id}',
            icon: 'kanban',
            finder: fn (int $id): ?array => $projects->findById($id),
        ));
    }

    /**
     * Bound for every installed module (like all module services); a
     * caller must still check Projects is available to the user
     * (ModuleGate) — and ProjectService re-checks permissions itself.
     */
    public function services(): array
    {
        return [ProjectService::class => $this->projectService];
    }

    /**
     * Every route: logged in + Projects available to the user (404
     * otherwise, never 403) + the permission key (RbacMiddleware). The
     * service then applies record scope, membership and status rules.
     * Fixed paths come before /{id} ({id} matches any segment).
     */
    public function routes(Router $router): void
    {
        $projects = $this->projectController;
        $settings = $this->settingsController;
        $rbac = fn (string $key) => new RbacMiddleware($key);

        $router->group('/projects', [new AuthMiddleware(), new ModuleMiddleware(self::KEY)], function (Router $router) use ($projects, $settings, $rbac) {
            $csrf = new CsrfMiddleware();

            $router->get('', fn (Request $r) => $projects->index($r), [$rbac(ProjectPermission::VIEW)]);
            $router->get('/create', fn (Request $r) => $projects->showCreate($r), [$rbac(ProjectPermission::CREATE)]);
            $router->post('', fn (Request $r) => $projects->create($r), [$rbac(ProjectPermission::CREATE), $csrf]);
            // "Link existing project" from a deal/contract panel; project_id comes from the form.
            $router->post('/links', fn (Request $r) => $projects->link($r), [$rbac(ProjectPermission::EDIT), $csrf]);

            $router->get('/settings', fn (Request $r) => $settings->index($r), [$rbac(ProjectPermission::MANAGE_SETTINGS)]);
            $router->post('/settings', fn (Request $r) => $settings->save($r), [$rbac(ProjectPermission::MANAGE_SETTINGS), $csrf]);

            $router->get('/{id}', fn (Request $r) => $projects->show($r), [$rbac(ProjectPermission::VIEW)]);
            $router->get('/{id}/edit', fn (Request $r) => $projects->showEdit($r), [$rbac(ProjectPermission::EDIT)]);
            $router->post('/{id}', fn (Request $r) => $projects->update($r), [$rbac(ProjectPermission::EDIT), $csrf]);
            $router->post('/{id}/delete', fn (Request $r) => $projects->delete($r), [$rbac(ProjectPermission::DELETE), $csrf]);
            $router->post('/{id}/status', fn (Request $r) => $projects->status($r), [$rbac(ProjectPermission::EDIT), $csrf]);
            $router->post('/{id}/members', fn (Request $r) => $projects->addMember($r), [$rbac(ProjectPermission::MANAGE_MEMBERS), $csrf]);
            $router->post('/{id}/members/{memberId}/delete', fn (Request $r) => $projects->removeMember($r), [$rbac(ProjectPermission::MANAGE_MEMBERS), $csrf]);
            $router->post('/{id}/links/{linkId}/delete', fn (Request $r) => $projects->unlink($r), [$rbac(ProjectPermission::EDIT), $csrf]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
