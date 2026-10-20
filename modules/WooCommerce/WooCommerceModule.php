<?php

declare(strict_types=1);

namespace Modules\WooCommerce;

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
use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\CurlHttpClient;
use App\Infrastructure\Integrations\IntegrationConnectionRepository;
use App\Infrastructure\Integrations\IntegrationRegistry;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Repositories\ContactEmailRepository;
use App\Infrastructure\Repositories\ContactPhoneRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\TagRepository;
use App\Infrastructure\Scheduling\ScheduledTasks;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\WooCommerce\Api\WooCommerceClient;
use Modules\WooCommerce\ContactSync\ContactSyncRepository;
use Modules\WooCommerce\ContactSync\ContactSyncSettingsService;
use Modules\WooCommerce\ContactSync\CustomerContactSync;
use Modules\WooCommerce\Controllers\ContactSyncController;
use Modules\WooCommerce\Segments\WooSegmentConditions;
use Modules\WooCommerce\Services\WooOrderImporter;

/**
 * WooCommerce integration (docs/integrations-architecture.md) — module
 * key "woocommerce", an add-on an organization must hold.
 *
 * Registers the "woocommerce" provider in Core's IntegrationRegistry;
 * everything else — Organization settings → Integrations, connection
 * storage + encryption, the webhook endpoint, the queue, cron
 * scheduling and retries — is Core's integration framework. This module
 * owns only the WooCommerce API client, the provider, and its own data
 * tables (woo_customers / woo_orders / woo_order_items / woo_products).
 *
 * Phase A — customer → contact sync (ContactSync/): per-shop settings at
 * /org/integrations/{id}/contact-sync (organization.edit + this module),
 * the cron task "woocommerce.contacts", and a "Shop orders" panel on
 * the contact page (contacts.view). Phase C — purchase conditions for
 * Marketing segments (Segments/). No new permission keys.
 */
final class WooCommerceModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'woocommerce';

    private ?WooCommerceProvider $provider = null;
    private ?WooOrderImporter $importer = null;
    private ?CustomerContactSync $contactSync = null;
    private ?ContactSyncController $contactSyncController = null;
    private ?ContactSyncSettingsService $contactSyncSettings = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        if (!$container->has(IntegrationRegistry::class)) {
            return;
        }
        $settings = (array) Config::get('integrations.woocommerce', []);
        $this->importer = new WooOrderImporter(new WooDataRepository(), new ContactRepository(), $container->make(EventBus::class));
        $this->provider = new WooCommerceProvider(
            new WooCommerceClient(new CurlHttpClient(), (int) ($settings['timeout_seconds'] ?? 20)),
            $this->importer,
            $settings,
        );
        $container->make(IntegrationRegistry::class)->register($this->provider);

        // Phase A: customer → CRM contact sync.
        $syncRepository = new ContactSyncRepository();
        $categories = new ContactCategoryRepository();
        $tags = new TagRepository();
        $this->contactSync = new CustomerContactSync(
            $syncRepository, new ContactRepository(), new ContactPhoneRepository(), new ContactEmailRepository(), $categories, $tags,
            $container->make(TimelineService::class), $container->make(OrganizationEntitlements::class), $container->make(LoggerInterface::class),
        );
        $this->importer->setContactSync($this->contactSync);
        $templates = $container->make(TemplateEngine::class);
        $this->contactSyncSettings = new ContactSyncSettingsService($syncRepository, $categories, $tags, $container->make(AuthorizationEngine::class));
        $this->contactSyncController = new ContactSyncController(
            $templates,
            $container->make(IntegrationConnectionRepository::class),
            $this->contactSyncSettings,
            $syncRepository,
            $this->contactSync,
            $categories,
        );

        $contactSync = $this->contactSync;
        $container->make(ScheduledTasks::class)->register('woocommerce.contacts', function (int $budget) use ($contactSync): string {
            $s = $contactSync->processAllEnabled($budget);

            return sprintf('created=%d matched=%d updated=%d skipped=%d conflict=%d', $s['created'], $s['matched'], $s['updated'], $s['skipped'], $s['conflict']);
        });

        // Phase C: purchase conditions for Marketing's dynamic segments (only when Marketing is installed;
        // its folder sorts first, so its registry is already in the container).
        if ($container->has(\Modules\Marketing\Segments\SegmentConditionRegistry::class)) {
            $container->make(\Modules\Marketing\Segments\SegmentConditionRegistry::class)->addProvider(new WooSegmentConditions());
        }

        // "Shop orders" on the contact page: the existing woo_orders rows of the contact's linked shop customers.
        $container->make(RelatedPanelRegistry::class)->register(new RelatedPanel(
            subjectType: 'contact',
            id: 'woocommerce.orders',
            module: self::KEY,
            titleKey: 'woocommerce.orders.panel_title',
            render: fn (array $contact, array $user): string => $templates->render('woocommerce/_contact_orders', [
                'orders' => $syncRepository->ordersForContact((int) $contact['id']),
            ]),
            order: 55,
            permission: Permission::CONTACTS_VIEW,
            icon: 'package',
        ));
    }

    public function services(): array
    {
        return [WooCommerceProvider::class => $this->provider, WooOrderImporter::class => $this->importer, CustomerContactSync::class => $this->contactSync, ContactSyncSettingsService::class => $this->contactSyncSettings];
    }

    public function routes(Router $router): void
    {
        $controller = $this->contactSyncController;
        if ($controller === null) {
            return;
        }
        $router->group('/org/integrations', [new AuthMiddleware(), new RbacMiddleware(Permission::ORGANIZATION_EDIT), new ModuleMiddleware(self::KEY)], function (Router $router) use ($controller) {
            $router->get('/{id}/contact-sync', fn (Request $r) => $controller->show($r));
            $router->post('/{id}/contact-sync', fn (Request $r) => $controller->save($r), [new CsrfMiddleware()]);
            $router->post('/{id}/contact-sync/run', fn (Request $r) => $controller->run($r), [new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
