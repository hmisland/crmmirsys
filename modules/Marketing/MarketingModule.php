<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\Channels\EmailChannel;
use Modules\Marketing\Channels\SmsChannel;
use Modules\Marketing\Controllers\CampaignController;
use Modules\Marketing\Controllers\MarketingController;
use Modules\Marketing\Controllers\PublicController;
use Modules\Marketing\Controllers\SegmentController;
use Modules\Marketing\Segments\ContactConditions;
use Modules\Marketing\Segments\SegmentAudience;
use Modules\Marketing\Segments\SegmentConditionRegistry;
use Modules\Marketing\Segments\SegmentPreviewRepository;
use Modules\Marketing\Segments\SegmentRules;
use Modules\Marketing\Services\SegmentService;
use Modules\Marketing\Services\AudienceResolver;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\Entitlements;
use Modules\Marketing\Services\InsightsService;
use Modules\Marketing\Services\MessageComposer;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\SuppressionService;
use Modules\Marketing\Services\TemplateService;
use Modules\Marketing\Services\TransactionalDelivery;
use Modules\Marketing\Services\UnsubscribeLinks;
use Modules\Marketing\Services\WebhookService;

/**
 * Marketing (docs/marketing-module.md): campaigns, audiences from the
 * existing contacts/groups/tags, templates, the send queue, history and
 * basic analytics, over a provider-neutral channel layer:
 *
 *   SMS   → SmsChannel   → core SmsService → Kavenegar (platform or own account)
 *   Email → EmailChannel → core EmailProviderService → Sender | Brevo
 *
 * Entitlement: "marketing" (this module: core + SMS campaigns) and
 * "marketing_email" (email channel, modules/MarketingEmail). Provider
 * credentials live in Organization settings → Messaging providers, not
 * here. Routes: login + ModuleMiddleware('marketing') + permission; the
 * services re-check permissions and every query is organization-scoped.
 */
final class MarketingModule implements ModuleInterface, ProvidesServices
{
    public const KEY = 'marketing';

    private ?CampaignController $campaignController = null;
    private ?MarketingController $marketingController = null;
    private ?PublicController $publicController = null;
    private ?QueueWorker $worker = null;
    private ?CampaignService $campaignService = null;
    private ?SegmentService $segmentService = null;
    private ?SegmentController $segmentController = null;
    private ?SegmentConditionRegistry $conditions = null;
    private ?ChannelRegistry $channels = null;
    private ?TransactionalDelivery $transactional = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        Permission::registerModuleKeys(self::KEY, MarketingPermission::ALL);

        $authorization = $container->make(AuthorizationEngine::class);
        $timeline = $container->make(TimelineService::class);
        $sms = $container->make(SmsService::class);
        $email = $container->make(EmailProviderService::class);
        $templates = $container->make(TemplateEngine::class);
        $appUrl = (string) Config::get('app.url', '');

        $campaigns = new CampaignRepository();
        $messages = new MessageRepository();
        $suppressions = new SuppressionRepository();
        $audienceRepository = new AudienceRepository();
        $entitlements = new Entitlements(new ModuleRepository(), new OrganizationModuleRepository());

        $channels = new ChannelRegistry();
        $channels->register(new SmsChannel($sms));
        $channels->register(new EmailChannel($email));
        $this->channels = $channels;

        $db = (array) Config::get('database.connections.' . Config::get('database.default'), []);
        $composer = new MessageComposer(
            $audienceRepository,
            new UnsubscribeLinks($appUrl),
            dirname(__DIR__, 2) . '/lang',
            $appUrl . '|' . ($db['database'] ?? ''),
        );
        // Phase 2 — segments: the condition allowlist (extensible through providers), rules, and the
        // bridge into the existing audience path (no second recipient-expansion system).
        $this->conditions = new SegmentConditionRegistry();
        $this->conditions->addProvider(new ContactConditions($audienceRepository));
        $segmentRules = new SegmentRules($this->conditions);
        $segmentRepository = new SegmentRepository();
        $segmentAudience = new SegmentAudience($segmentRepository, $segmentRules);
        $audienceRepository->setSegmentAudience($segmentAudience);

        $audience = new AudienceResolver($audienceRepository, $suppressions, $segmentRepository);
        $templateService = new TemplateService(new TemplateRepository(), $authorization);

        $limits = (array) Config::get('messaging.marketing', []);
        $this->campaignService = new CampaignService(
            $campaigns, $messages, $audience, $composer, $channels, $authorization,
            $container->make(ModuleGate::class), $timeline, $email,
            new ActionRateLimiter(),
            (int) ($limits['sync_audience_limit'] ?? 2000),
            (int) ($limits['transactional_max_recipients'] ?? 50),
            $segmentAudience,
        );
        $this->segmentService = new SegmentService($segmentRepository, $segmentRules, $this->conditions, new SegmentPreviewRepository($segmentRules, $segmentAudience), $authorization, $timeline);
        $this->segmentController = new SegmentController($templates, $this->segmentService);
        $this->worker = new QueueWorker(
            $campaigns, $messages, $suppressions, $channels, $entitlements, $composer, $sms, $timeline,
            $container->make(LoggerInterface::class),
            $audience,
        );
        // Phase 2: single transactional messages for other modules (Core TransactionalDeliveryInterface),
        // through the same channels, opt-out list and entitlements as campaigns.
        $this->transactional = new TransactionalDelivery(
            new TransactionalMessageRepository(), $suppressions, $channels, $entitlements, $composer, $email, $sms, $timeline,
            $container->make(LoggerInterface::class),
        );
        $suppressionService = new SuppressionService($suppressions, $messages, $authorization, $timeline);

        $this->campaignController = new CampaignController($templates, $this->campaignService, $templateService, $this->worker, $audienceRepository, $messages, $email, $this->segmentService);
        $this->marketingController = new MarketingController(
            $templates,
            new InsightsService($campaigns, $messages, $sms, $email, $authorization),
            $this->campaignService,
            $suppressionService,
            $templateService,
        );
        $this->publicController = new PublicController(
            $templates,
            $suppressionService,
            new WebhookService($email, $messages, $suppressions, new WebhookEventRepository(), $entitlements, $timeline),
        );

        // "Marketing messages" box on the contact page (send history per contact).
        $container->make(RelatedPanelRegistry::class)->register(new RelatedPanel(
            subjectType: 'contact',
            id: 'marketing.contact',
            module: self::KEY,
            titleKey: 'marketing.panel_title',
            render: fn (array $contact, array $user): string => $templates->render('marketing/_contact_panel', [
                'contact' => $contact,
                'rows' => $messages->page(['contact_id' => (int) $contact['id']], 10, 0),
                'canCreate' => $authorization->can($user, MarketingPermission::CREATE),
                // Channels this organization may use (a module-added channel such as Bale gets its own shortcut).
                'channels' => $this->campaignService?->availableChannels($user) ?? [],
            ]),
            order: 60,
            permission: MarketingPermission::VIEW,
            icon: 'megaphone',
        ));
    }

    /** The worker, for modules/Marketing/bin/worker.php (cron). */
    public function worker(): ?QueueWorker
    {
        return $this->worker;
    }

    public function services(): array
    {
        // SegmentConditionRegistry: the extension point for future data providers (e.g. WooCommerce) to add conditions.
        // ChannelRegistry: modules that add a channel (e.g. MarketingBale) register it here.
        // TransactionalDeliveryInterface: Core's delivery boundary (e.g. Customer Satisfaction invitations).
        return [TransactionalDeliveryInterface::class => $this->transactional, ChannelRegistry::class => $this->channels, CampaignService::class => $this->campaignService, QueueWorker::class => $this->worker, SegmentService::class => $this->segmentService, SegmentConditionRegistry::class => $this->conditions];
    }

    public function routes(Router $router): void
    {
        $campaigns = $this->campaignController;
        $marketing = $this->marketingController;
        $segments = $this->segmentController;
        $public = $this->publicController;
        $rbac = fn (string $key) => new RbacMiddleware($key);

        $router->group('/marketing', [new AuthMiddleware(), new ModuleMiddleware(self::KEY), new RequireOrganizationMiddleware()], function (Router $router) use ($campaigns, $marketing, $segments, $rbac) {
            $csrf = new CsrfMiddleware();
            $view = $rbac(MarketingPermission::VIEW);

            $router->get('', fn (Request $r) => $marketing->dashboard($r), [$view]);
            $router->get('/history', fn (Request $r) => $marketing->history($r), [$view]);

            $router->get('/suppressions', fn (Request $r) => $marketing->suppressions($r), [$view]);
            $router->post('/suppressions', fn (Request $r) => $marketing->addSuppression($r), [$rbac(MarketingPermission::MANAGE_SUPPRESSIONS), $csrf]);
            $router->post('/suppressions/{id}/delete', fn (Request $r) => $marketing->removeSuppression($r), [$rbac(MarketingPermission::MANAGE_SUPPRESSIONS), $csrf]);

            $router->get('/templates', fn (Request $r) => $marketing->templates($r), [$view]);
            $router->get('/templates/create', fn (Request $r) => $marketing->templateForm($r), [$rbac(MarketingPermission::MANAGE_TEMPLATES)]);
            $router->post('/templates', fn (Request $r) => $marketing->saveTemplate($r), [$rbac(MarketingPermission::MANAGE_TEMPLATES), $csrf]);
            $router->get('/templates/{id}/edit', fn (Request $r) => $marketing->templateForm($r), [$rbac(MarketingPermission::MANAGE_TEMPLATES)]);
            $router->post('/templates/{id}', fn (Request $r) => $marketing->saveTemplate($r), [$rbac(MarketingPermission::MANAGE_TEMPLATES), $csrf]);
            $router->post('/templates/{id}/delete', fn (Request $r) => $marketing->deleteTemplate($r), [$rbac(MarketingPermission::MANAGE_TEMPLATES), $csrf]);

            // Phase 2 — segments (fixed paths before /{id}).
            $manageSegments = $rbac(MarketingPermission::MANAGE_SEGMENTS);
            $router->get('/segments', fn (Request $r) => $segments->index($r), [$view]);
            $router->get('/segments/create', fn (Request $r) => $segments->create($r), [$manageSegments]);
            $router->post('/segments', fn (Request $r) => $segments->store($r), [$manageSegments, $csrf]);
            $router->get('/segments/{id}', fn (Request $r) => $segments->show($r), [$view]);
            $router->get('/segments/{id}/edit', fn (Request $r) => $segments->edit($r), [$manageSegments]);
            $router->post('/segments/{id}', fn (Request $r) => $segments->update($r), [$manageSegments, $csrf]);
            $router->post('/segments/{id}/delete', fn (Request $r) => $segments->delete($r), [$manageSegments, $csrf]);
            $router->post('/segments/{id}/members', fn (Request $r) => $segments->addMembers($r), [$manageSegments, $csrf]);
            $router->post('/segments/{id}/members/remove', fn (Request $r) => $segments->removeMembers($r), [$manageSegments, $csrf]);

            $router->get('/campaigns', fn (Request $r) => $campaigns->index($r), [$view]);
            $router->get('/campaigns/create', fn (Request $r) => $campaigns->create($r), [$rbac(MarketingPermission::CREATE)]);
            $router->post('/campaigns', fn (Request $r) => $campaigns->store($r), [$rbac(MarketingPermission::CREATE), $csrf]);
            $router->get('/campaigns/{id}', fn (Request $r) => $campaigns->show($r), [$view]);
            $router->get('/campaigns/{id}/edit', fn (Request $r) => $campaigns->edit($r), [$rbac(MarketingPermission::CREATE)]);
            $router->post('/campaigns/{id}', fn (Request $r) => $campaigns->update($r), [$rbac(MarketingPermission::CREATE), $csrf]);
            $router->post('/campaigns/{id}/delete', fn (Request $r) => $campaigns->delete($r), [$rbac(MarketingPermission::CREATE), $csrf]);
            $router->post('/campaigns/{id}/test', fn (Request $r) => $campaigns->test($r), [$rbac(MarketingPermission::CREATE), $csrf]);
            $router->post('/campaigns/{id}/send', fn (Request $r) => $campaigns->send($r), [$rbac(MarketingPermission::SEND), $csrf]);
            $router->post('/campaigns/{id}/cancel', fn (Request $r) => $campaigns->cancel($r), [$rbac(MarketingPermission::SEND), $csrf]);
            $router->post('/campaigns/{id}/retry', fn (Request $r) => $campaigns->retry($r), [$rbac(MarketingPermission::SEND), $csrf]);
            $router->post('/campaigns/{id}/messages/{message}/retry-unknown', fn (Request $r) => $campaigns->retryUnknown($r), [$rbac(MarketingPermission::SEND), $csrf]);
            $router->post('/campaigns/{id}/process', fn (Request $r) => $campaigns->process($r), [$view, $csrf]);
        });

        // Public: signed unsubscribe links and provider webhooks (no login, no CSRF — see PublicController).
        $router->get('/m/unsubscribe/{token}', fn (Request $r) => $public->unsubscribeForm($r));
        $router->post('/m/unsubscribe/{token}', fn (Request $r) => $public->unsubscribe($r));
        $router->post('/webhooks/email/{provider}/{key}', fn (Request $r) => $public->webhook($r));
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
