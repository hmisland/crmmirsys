<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction;

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
use App\Infrastructure\Integrations\Events\ExternalOrderStatusChanged;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\PublicLinks\PublicLinkRegistry;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Scheduling\ScheduledTasks;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerSatisfaction\Controllers\CxController;
use Modules\CustomerSatisfaction\Listeners\OrderStatusListener;
use Modules\CustomerSatisfaction\PublicPages\SurveyLinkHandler;
use Modules\CustomerSatisfaction\Services\InvitationService;
use Modules\CustomerSatisfaction\Services\ResponseService;
use Modules\CustomerSatisfaction\Services\SurveyService;

/**
 * Customer Satisfaction (docs/integrations-architecture.md) — module key
 * "customer_satisfaction", independent of any data source and of
 * Marketing:
 *
 *  - listens to Core's source-neutral ExternalOrderStatusChanged event
 *    (WooCommerce today, any shop later) → scheduled invitations;
 *  - "cx.invitations" scheduled task → issues a single-use public link
 *    (Core PublicLinkService) and asks Core's TransactionalDeliveryInterface
 *    (implemented by Marketing) to deliver ONE message — it never calls
 *    an SMS/Email/Bale provider itself;
 *  - "cx_survey" public link handler → the mobile survey page at /p/{token};
 *  - /cx → surveys, invitations, responses, follow-ups (cx.view / cx.manage).
 */
final class CustomerSatisfactionModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'customer_satisfaction';

    private ?CxController $controller = null;
    private ?InvitationService $invitations = null;
    private ?ResponseService $responses = null;
    private ?SurveyService $surveys = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        Permission::registerModuleKeys(self::KEY, CxPermission::ALL);
        if (!$container->has(PublicLinkService::class)) {
            return;
        }

        $repository = new CxRepository();
        $links = $container->make(PublicLinkService::class);
        $templates = $container->make(TemplateEngine::class);
        $langPath = dirname(__DIR__, 2) . '/lang';

        $this->surveys = new SurveyService($repository, $container->make(AuthorizationEngine::class));
        $this->responses = new ResponseService($repository, $links);
        $this->invitations = new InvitationService($repository, $links, $container->make(OrganizationEntitlements::class), $container, $langPath);
        $this->controller = new CxController(
            $templates, $this->surveys, $repository,
            fn (): bool => $container->has(TransactionalDeliveryInterface::class),
            $this->invitations,
            fn (): ?TransactionalDeliveryInterface => $container->has(TransactionalDeliveryInterface::class) ? $container->make(TransactionalDeliveryInterface::class) : null,
        );

        $container->make(EventBus::class)->listen(ExternalOrderStatusChanged::class, new OrderStatusListener($this->invitations));
        $container->make(PublicLinkRegistry::class)->register(new SurveyLinkHandler($templates, $this->responses, $links));

        $invitations = $this->invitations;
        $container->make(ScheduledTasks::class)->register('cx.invitations', function (int $budget) use ($invitations): string {
            $s = $invitations->dispatchDue($budget);

            return sprintf('sent=%d waiting=%d failed=%d expired=%d', $s['sent'], $s['waiting'], $s['failed'], $s['expired']);
        });
    }

    public function services(): array
    {
        return [InvitationService::class => $this->invitations, ResponseService::class => $this->responses, SurveyService::class => $this->surveys];
    }

    public function routes(Router $router): void
    {
        $controller = $this->controller;
        if ($controller === null) {
            return;
        }

        $router->group('/cx', [new AuthMiddleware(), new ModuleMiddleware(self::KEY)], function (Router $router) use ($controller) {
            $view = new RbacMiddleware(CxPermission::VIEW);
            $manage = new RbacMiddleware(CxPermission::MANAGE);
            $csrf = new CsrfMiddleware();

            $router->get('', fn (Request $r) => $controller->index($r), [$view]);
            $router->get('/surveys/create', fn (Request $r) => $controller->form($r), [$manage]);
            $router->post('/surveys', fn (Request $r) => $controller->save($r), [$manage, $csrf]);
            $router->get('/surveys/{id}/edit', fn (Request $r) => $controller->form($r), [$manage]);
            $router->post('/surveys/{id}', fn (Request $r) => $controller->save($r), [$manage, $csrf]);
            $router->post('/responses/{id}/follow-up', fn (Request $r) => $controller->closeFollowUp($r), [$manage, $csrf]);
            $router->post('/test', fn (Request $r) => $controller->sendTest($r), [$manage, $csrf]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
