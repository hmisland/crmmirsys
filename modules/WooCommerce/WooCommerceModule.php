<?php

declare(strict_types=1);

namespace Modules\WooCommerce;

use App\Core\Container;
use App\Core\Events\EventBus;
use App\Core\Router;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\CurlHttpClient;
use App\Infrastructure\Integrations\IntegrationRegistry;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Repositories\ContactRepository;
use Modules\WooCommerce\Api\WooCommerceClient;
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
 * No routes and no new permission keys: connections are managed with
 * organization.edit, like the messaging providers.
 */
final class WooCommerceModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'woocommerce';

    private ?WooCommerceProvider $provider = null;
    private ?WooOrderImporter $importer = null;

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
    }

    public function services(): array
    {
        return [WooCommerceProvider::class => $this->provider, WooOrderImporter::class => $this->importer];
    }

    public function routes(Router $router): void
    {
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
