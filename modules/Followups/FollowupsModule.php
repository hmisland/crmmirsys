<?php

declare(strict_types=1);

namespace Modules\Followups;

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
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Followups\Controllers\FollowUpController;
use Modules\Followups\Controllers\FollowUpRuleController;
use Modules\Followups\Listeners\FollowUpRuleEngineListener;
use Modules\Followups\Services\FollowUpRuleService;
use Modules\Followups\Services\FollowUpService;
use Modules\Sales\Events\DealStageChanged;

/**
 * MVP scope per the amendment's §25: Follow-up CRUD, entity linking,
 * assignment, due date/timezone, priority, status, complete/cancel/
 * reschedule, reminders (data model, IN_APP channel active),
 * timeline integration, RBAC, tenant isolation, audit (via the
 * existing TimelineService — no second audit system), basic
 * event-driven automation (DealStageChanged wired end-to-end as the
 * proof), idempotency. Deferred per the same section: advanced
 * recurrence logic, escalation, SMS/push, external calendar
 * integrations, advanced workflow branching, the REST API (explicitly
 * deferred by the client).
 */
final class FollowupsModule implements ModuleInterface
{
    private ?FollowUpController $followUpController = null;
    private ?FollowUpRuleController $ruleController = null;
    private ?FollowUpService $followUpServiceInstance = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Core (the Dashboard) can optionally read the top follow-ups without hard-depending on this module — same lazy pattern as Finance's service getters. */
    public function followUpService(): ?FollowUpService
    {
        return $this->followUpServiceInstance;
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);
        $templates = $container->make(TemplateEngine::class);
        $timeline = $container->make(TimelineService::class);

        $followUpRepo = new FollowUpRepository();
        $reminderRepo = new FollowUpReminderRepository();
        $ruleRepo = new FollowUpRuleRepository();
        $executionRepo = new FollowUpRuleExecutionRepository();

        $followUpService = new FollowUpService($followUpRepo, $authorization, $timeline);
        $this->followUpServiceInstance = $followUpService;
        $ruleService = new FollowUpRuleService($ruleRepo, $authorization);

        $recordTypes = $container->make(RecordTypeRegistry::class);
        $this->followUpController = new FollowUpController($templates, $followUpService, new UserRepository(), new ContactRepository(), new LeadRepository(), $recordTypes);

        // "Follow-ups" boxes on contract and project pages, via the Core panel
        // slot only (FollowUpPanels explains the access rules).
        (new FollowUpPanels($followUpService, $authorization, $recordTypes, $templates))->registerAll($container->make(RelatedPanelRegistry::class));
        $this->ruleController = new FollowUpRuleController($templates, $ruleService);

        // The actual activation step: without this, the rule tables
        // and the listener class would exist but nothing would ever
        // call handle() — DealStageChanged would be dispatched by
        // DealService into an EventBus with no one listening.
        $listener = new FollowUpRuleEngineListener($ruleRepo, $executionRepo, $reminderRepo, $followUpService);
        $container->make(EventBus::class)->listen(DealStageChanged::class, $listener);
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('followups');
        $followUpController = $this->followUpController;
        $ruleController = $this->ruleController;

        $router->group('/followups', [$auth, $module], function (Router $router) use ($followUpController, $ruleController) {
            $router->get('', fn (Request $r) => $followUpController->index($r), [new RbacMiddleware('followups.view')]);
            $router->get('/create', fn (Request $r) => $followUpController->showCreate($r), [new RbacMiddleware('followups.create')]);
            $router->post('', fn (Request $r) => $followUpController->create($r), [new RbacMiddleware('followups.create'), new CsrfMiddleware()]);

            $router->get('/rules', fn (Request $r) => $ruleController->index($r), [new RbacMiddleware('followups.manage_rules')]);
            $router->post('/rules', fn (Request $r) => $ruleController->create($r), [new RbacMiddleware('followups.manage_rules'), new CsrfMiddleware()]);
            $router->post('/rules/{id}/toggle', fn (Request $r) => $ruleController->toggle($r), [new RbacMiddleware('followups.manage_rules'), new CsrfMiddleware()]);
            $router->post('/rules/{id}/delete', fn (Request $r) => $ruleController->delete($r), [new RbacMiddleware('followups.manage_rules'), new CsrfMiddleware()]);

            $router->post('/bulk-delete', fn (Request $r) => $followUpController->bulkDelete($r), [new RbacMiddleware('followups.delete'), new CsrfMiddleware()]);
            $router->get('/{id}', fn (Request $r) => $followUpController->show($r), [new RbacMiddleware('followups.view')]);
            $router->get('/{id}/edit', fn (Request $r) => $followUpController->showEdit($r), [new RbacMiddleware('followups.edit')]);
            $router->post('/{id}', fn (Request $r) => $followUpController->update($r), [new RbacMiddleware('followups.edit'), new CsrfMiddleware()]);
            $router->post('/{id}/delete', fn (Request $r) => $followUpController->delete($r), [new RbacMiddleware('followups.delete'), new CsrfMiddleware()]);
            $router->post('/{id}/complete', fn (Request $r) => $followUpController->complete($r), [new RbacMiddleware('followups.complete'), new CsrfMiddleware()]);
            $router->post('/{id}/cancel', fn (Request $r) => $followUpController->cancel($r), [new RbacMiddleware('followups.cancel'), new CsrfMiddleware()]);
            $router->post('/{id}/reschedule', fn (Request $r) => $followUpController->reschedule($r), [new RbacMiddleware('followups.edit'), new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
