<?php

declare(strict_types=1);

namespace Modules\MarketingBale;

use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Messaging\MessagingSettingsCards;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\MarketingBale\Billing\BillableMessageSink;
use Modules\MarketingBale\Billing\NullBillableMessageSink;
use Modules\MarketingBale\Channels\BaleChannel;
use Modules\MarketingBale\Controllers\BaleSettingsController;
use Modules\MarketingBale\Safir\CurlSafirTransport;
use Modules\MarketingBale\Safir\SafirClient;
use Modules\MarketingBale\Services\BaleSettingsService;

/**
 * Bale messaging through Safir (docs/bale-messaging-module.md) — module
 * key "bale_messaging", depends on "marketing".
 *
 * Adds the "bale" channel to Marketing's ChannelRegistry, so campaigns,
 * audiences (contacts, group, tag, segment), approval, the queue and
 * worker, opt-outs and reports are Marketing's own; and a Bale card on
 * Organization settings → Messaging providers for the organization's
 * own Safir key + bot id.
 *
 * The folder sorts after "Marketing" (module discovery is alphabetical),
 * so Marketing's ChannelRegistry is already in the container here.
 * No new permission keys: Marketing's marketing.* keys apply, settings
 * need organization.edit.
 */
final class MarketingBaleModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'bale_messaging';

    private ?BaleSettingsService $settings = null;
    private ?BaleChannel $channel = null;
    private ?BaleSettingsController $controller = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        if (!$container->has(ChannelRegistry::class)) {
            return; // Marketing not installed: nothing to attach to.
        }
        $config = (array) Config::get('messaging.bale', []);

        $this->settings = new BaleSettingsService(new BaleSettingsRepository(), $container->make(TimelineService::class));
        $billing = $container->has(BillableMessageSink::class) ? $container->make(BillableMessageSink::class) : new NullBillableMessageSink();
        $this->channel = new BaleChannel(
            new SafirClient(
                new CurlSafirTransport((int) ($config['connect_timeout_seconds'] ?? 5), (int) ($config['timeout_seconds'] ?? 15)),
                (string) ($config['base_url'] ?? SafirClient::DEFAULT_BASE_URL),
            ),
            $this->settings,
            $billing,
            $container->make(LoggerInterface::class),
            (float) ($config['requests_per_second'] ?? 5),
            (int) ($config['per_run'] ?? 200),
            (int) ($config['pause_seconds'] ?? 600),
            (int) ($config['rate_limit_backoff_seconds'] ?? 60),
            (int) ($config['approvals_per_user_per_hour'] ?? 20),
        );
        $container->make(ChannelRegistry::class)->register($this->channel);

        $this->controller = new BaleSettingsController($container->make(TemplateEngine::class), $this->settings, $container->make(ModuleGate::class));
        $controller = $this->controller;
        $container->make(MessagingSettingsCards::class)->register('bale', fn (array $user): string => $controller->card($user));
    }

    public function services(): array
    {
        return [BaleSettingsService::class => $this->settings, BaleChannel::class => $this->channel];
    }

    public function routes(Router $router): void
    {
        $controller = $this->controller;
        if ($controller === null) {
            return;
        }
        $edit = fn () => [new RbacMiddleware('organization.edit'), new CsrfMiddleware()];

        // Entitlement (marketing + bale_messaging) is checked inside the controller on every POST.
        $router->group('/org/messaging/bale', [new AuthMiddleware()], function (Router $router) use ($controller, $edit) {
            $router->post('', fn (Request $r) => $controller->save($r), $edit());
            $router->post('/disconnect', fn (Request $r) => $controller->disconnect($r), $edit());
            $router->post('/enabled', fn (Request $r) => $controller->setEnabled($r), $edit());
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
