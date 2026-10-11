<?php

declare(strict_types=1);

namespace Modules\Contracts;

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
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Contracts\Controllers\ContractCategoryController;
use Modules\Contracts\Controllers\ContractController;
use Modules\Contracts\Controllers\ContractSettingsController;
use Modules\Contracts\Listeners\DealStageChangedListener;
use App\Infrastructure\Logging\LoggerInterface;
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
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\NumberSeriesRepository;
use Modules\Contracts\Services\ContractService;

/**
 * Contracts — an independently sellable module (docs/contracts-module-design.md).
 * Runs with Core alone: it depends on no other module and imports none.
 * Everything it adds to the rest of the app goes through the Core
 * extension points (docs/module-extension-points.md).
 *
 * Built incrementally: 2.2 skeleton, tables, `contract` record type;
 * 2.3 permissions (ContractPermission + grant migration 0003); 2.4
 * ContractService (exposed via ProvidesServices); 2.5 pages and routes.
 * Panels (2.6) and deal integration (2.7–2.9) come next.
 */
final class ContractsModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'contracts';
    public const RECORD_TYPE = 'contract';

    /**
     * The one place another module is named — as a string, never imported
     * (decision G2). The architecture test allows exactly this string,
     * here only. If Sales renames the event, ContractDealLinkTest fails.
     */
    public const DEAL_STAGE_CHANGED_EVENT = 'Modules\\Sales\\Events\\DealStageChanged';

    private ?ContractService $contractService = null;
    private ?ContractController $contractController = null;
    private ?ContractCategoryController $categoryController = null;
    private ?ContractSettingsController $settingsController = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        // Own keys under the "contracts." prefix — Core doesn't list them.
        Permission::registerModuleKeys(self::KEY, ContractPermission::ALL);

        $contracts = new ContractRepository();
        $links = new ContractLinkRepository();

        // Core services and repositories only.
        $this->contractService = new ContractService(
            $contracts,
            new ContractCategoryRepository(),
            new NumberSeriesRepository(),
            $container->make(AuthorizationEngine::class),
            $container->make(TimelineService::class),
            $container->make(EventBus::class),
            new ContactRepository(),
            new CompanyRepository(),
            new CurrencyRepository(),
            $links,
            $container->make(RecordTypeRegistry::class),
        );

        // Deal → Contract (step 2.7): subscribed by class NAME only — no import
        // of Sales. Never fires when Sales isn't installed.
        $container->make(EventBus::class)->listen(self::DEAL_STAGE_CHANGED_EVENT, new DealStageChangedListener(
            $links,
            $container->make(TimelineService::class),
            $container->make(LoggerInterface::class),
            $this->contractService,
        ));

        $templates = $container->make(TemplateEngine::class);
        $this->contractController = new ContractController(
            $templates,
            $this->contractService,
            $container->make(NoteService::class),
            $container->make(TagService::class),
            $container->make(TimelineService::class),
            $container->make(AttachmentService::class),
            new ContactRepository(),
            new CompanyRepository(),
            new CurrencyRepository(),
        );
        $this->categoryController = new ContractCategoryController($templates, $this->contractService);
        $this->settingsController = new ContractSettingsController($templates, $this->contractService);

        // "Contracts" boxes on contact and company pages (step 2.6) — via the
        // Core panel slot only; ContractPanels explains the access rules.
        (new ContractPanels($this->contractService, $templates))->registerAll($container->make(RelatedPanelRegistry::class));

        // Notes, tags, attachments and timeline on a contract, and other
        // modules' lookups, all go through this registration. Usable
        // only when Contracts is available to the user and they hold
        // contracts.view (ModuleGate) — never just because it's installed.
        $container->make(RecordTypeRegistry::class)->register(new RecordType(
            key: self::RECORD_TYPE,
            module: self::KEY,
            viewPermission: ContractPermission::VIEW,
            labelKey: 'contracts.record',
            showPath: '/contracts/{id}',
            icon: 'file-text',
            finder: fn (int $id): ?array => $contracts->findById($id),
        ));
    }

    /**
     * Bound for every installed module (like all module services); a
     * caller must still check Contracts is available to the user
     * (ModuleGate) — and ContractService re-checks permissions itself.
     */
    public function services(): array
    {
        return [ContractService::class => $this->contractService];
    }

    /**
     * Every route: logged in + Contracts available to the user (404
     * otherwise, never 403) + the permission key (RbacMiddleware). The
     * service then applies record scope and status rules. Fixed paths
     * come before /{id} ({id} matches any segment).
     */
    public function routes(Router $router): void
    {
        $contracts = $this->contractController;
        $categories = $this->categoryController;
        $settings = $this->settingsController;
        $rbac = fn (string $key) => new RbacMiddleware($key);

        $router->group('/contracts', [new AuthMiddleware(), new ModuleMiddleware(self::KEY)], function (Router $router) use ($contracts, $categories, $settings, $rbac) {
            $csrf = new CsrfMiddleware();

            $router->get('', fn (Request $r) => $contracts->index($r), [$rbac(ContractPermission::VIEW)]);
            $router->get('/create', fn (Request $r) => $contracts->showCreate($r), [$rbac(ContractPermission::CREATE)]);
            $router->post('', fn (Request $r) => $contracts->create($r), [$rbac(ContractPermission::CREATE), $csrf]);

            $router->get('/settings', fn (Request $r) => $settings->index($r), [$rbac(ContractPermission::MANAGE_SETTINGS)]);
            $router->post('/settings', fn (Request $r) => $settings->save($r), [$rbac(ContractPermission::MANAGE_SETTINGS), $csrf]);

            $router->get('/categories', fn (Request $r) => $categories->index($r), [$rbac(ContractPermission::MANAGE_SETTINGS)]);
            $router->post('/categories', fn (Request $r) => $categories->create($r), [$rbac(ContractPermission::MANAGE_SETTINGS), $csrf]);
            $router->post('/categories/{id}', fn (Request $r) => $categories->rename($r), [$rbac(ContractPermission::MANAGE_SETTINGS), $csrf]);
            $router->post('/categories/{id}/delete', fn (Request $r) => $categories->delete($r), [$rbac(ContractPermission::MANAGE_SETTINGS), $csrf]);

            $router->get('/{id}', fn (Request $r) => $contracts->show($r), [$rbac(ContractPermission::VIEW)]);
            $router->get('/{id}/edit', fn (Request $r) => $contracts->showEdit($r), [$rbac(ContractPermission::EDIT)]);
            $router->post('/{id}', fn (Request $r) => $contracts->update($r), [$rbac(ContractPermission::EDIT), $csrf]);
            $router->post('/{id}/delete', fn (Request $r) => $contracts->delete($r), [$rbac(ContractPermission::DELETE), $csrf]);
            $router->post('/{id}/submit', fn (Request $r) => $contracts->submit($r), [$rbac(ContractPermission::EDIT), $csrf]);
            $router->post('/{id}/return', fn (Request $r) => $contracts->returnToDraft($r), [$rbac(ContractPermission::APPROVE), $csrf]);
            $router->post('/{id}/approve', fn (Request $r) => $contracts->approve($r), [$rbac(ContractPermission::APPROVE), $csrf]);
            $router->post('/{id}/activate', fn (Request $r) => $contracts->activate($r), [$rbac(ContractPermission::ACTIVATE), $csrf]);
            $router->post('/{id}/cancel', fn (Request $r) => $contracts->cancel($r), [$rbac(ContractPermission::EDIT), $csrf]);
            $router->post('/{id}/terminate', fn (Request $r) => $contracts->terminate($r), [$rbac(ContractPermission::TERMINATE), $csrf]);
            $router->post('/{id}/renew', fn (Request $r) => $contracts->renew($r), [$rbac(ContractPermission::RENEW), $csrf]);
            $router->post('/{id}/links/{linkId}/delete', fn (Request $r) => $contracts->unlink($r), [$rbac(ContractPermission::EDIT), $csrf]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
