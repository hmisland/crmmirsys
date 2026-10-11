<?php
/**
 * CRM GROUP by MIRSYS — crmgroup.ir
 * Copyright (c) 2026 MIRSYS. All rights reserved.
 */

declare(strict_types=1);

namespace App\Core;

use App\Application\Services\AuthService;
use App\Application\Services\BankAccountService;
use App\Application\Services\BankService;
use App\Application\Services\ContactCategoryService;
use App\Application\Services\AttachmentService;
use App\Application\Services\CompanyService;
use App\Application\Services\ContactService;
use App\Application\Services\ContactImportExportService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\LeadService;
use App\Application\Services\ModuleService;
use App\Application\Services\NoteService;
use App\Application\Services\OrganizationService;
use App\Application\Services\OrgStructureService;
use App\Application\Services\RoleService;
use App\Application\Services\SearchService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Application\Services\UserManagementService;
use App\Core\Events\EventBus;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Config\EnvLoader;
use App\Infrastructure\ErrorHandling\ErrorHandler;
use App\Infrastructure\Logging\FileLogger;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Mail\LogMailer;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Mail\PhpMailMailer;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Modules\ModuleServiceRegistrar;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\AttachmentRepository;
use App\Infrastructure\Repositories\AuthTokenRepository;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactEmailRepository;
use App\Infrastructure\Repositories\ContactInterestRepository;
use App\Infrastructure\Repositories\ContactPhoneRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\ContactSocialProfileRepository;
use App\Infrastructure\Repositories\CustomFieldDefinitionRepository;
use App\Infrastructure\Repositories\CustomFieldValueRepository;
use App\Infrastructure\Repositories\DepartmentRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Repositories\LoginAttemptRepository;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\NoteRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\RolePermissionRepository;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\TagRepository;
use App\Infrastructure\Repositories\TeamRepository;
use App\Infrastructure\Repositories\TimelineEventRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Repositories\UserPermissionGrantRepository;
use App\Infrastructure\Repositories\SmsLogRepository;
use App\Infrastructure\Repositories\OrganizationSmsSettingsRepository;
use App\Infrastructure\Sms\KavenegarSmsSender;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Application\Services\SmsService;
use App\Infrastructure\Repositories\ContactImportBatchRepository;
use App\Infrastructure\Repositories\ContactLinkRepository;
use App\Infrastructure\Repositories\BankRepository;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Repositories\UserBankAccountRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\LoginThrottle;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use App\Infrastructure\Theme\ThemeLoader;

/**
 * The application kernel. One object, built once per request by
 * public/index.php, that owns constructing every other foundation
 * piece in the right order and exposes the Container and Router that
 * everything else (routes/*.php, module ServiceProviders from Phase 4
 * onward) is registered against.
 */
final class Application
{
    public readonly Container $container;
    public readonly Router $router;

    public function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->router = new Router();

        $this->loadEnvironmentAndConfig();
        $this->configurePhpRuntime();
        $this->registerCoreBindings();
        $this->bootstrapModuleRegistry();
        $this->registerErrorHandling();
    }

    private function loadEnvironmentAndConfig(): void
    {
        EnvLoader::load($this->basePath . '/.env');
        Config::bootstrap($this->basePath . '/config');
    }

    private function configurePhpRuntime(): void
    {
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        $debug = (bool) Config::get('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);
    }

    private function registerCoreBindings(): void
    {
        $this->container->singleton(LoggerInterface::class, fn () => new FileLogger(
            $this->basePath . '/storage/logs',
            (string) Config::get('app.log_level', 'debug')
        ));

        $this->container->singleton(EventBus::class, fn (Container $c) => new EventBus($c->make(LoggerInterface::class)));

        $this->container->singleton(ThemeLoader::class, fn () => new ThemeLoader($this->basePath . '/themes'));

        $this->container->singleton(TemplateEngine::class, fn (Container $c) => new TemplateEngine(
            $c->make(ThemeLoader::class),
            $c->make(RelatedPanelRegistry::class)
        ));

        // Extension points modules plug into from their register()
        // (docs/module-extension-points.md). Access is always decided by
        // the existing ModuleAccessResolver + AuthorizationEngine.
        $this->container->singleton(ModuleGate::class, fn (Container $c) => ModuleGate::fromCore(
            new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository()),
            $c->make(AuthorizationEngine::class)
        ));

        $this->container->singleton(RecordTypeRegistry::class, function (Container $c) {
            $registry = new RecordTypeRegistry($c->make(ModuleGate::class), $c->make(LoggerInterface::class));
            $registry->register(new RecordType('contact', null, Permission::CONTACTS_VIEW, 'nav.contacts', '/contacts/{id}', 'users'));
            $registry->register(new RecordType('company', null, Permission::COMPANIES_VIEW, 'nav.companies', '/companies/{id}', 'building'));
            $registry->register(new RecordType('lead', null, Permission::LEADS_VIEW, 'nav.leads', '/leads/{id}', 'target'));

            return $registry;
        });

        $this->container->singleton(RelatedPanelRegistry::class, fn (Container $c) => new RelatedPanelRegistry(
            $c->make(ModuleGate::class),
            $c->make(LoggerInterface::class)
        ));

        $this->container->singleton(ModuleServiceRegistrar::class, fn (Container $c) => new ModuleServiceRegistrar($c->make(LoggerInterface::class)));

        $this->container->singleton(MailerInterface::class, fn () => match (Config::get('mail.driver', 'log')) {
            'php_mail' => new PhpMailMailer(
                (string) Config::get('mail.from_address', 'no-reply@example.com'),
                (string) Config::get('mail.from_name', Config::get('app.name', 'CRM Platform'))
            ),
            'smtp' => new SmtpMailer(
                (string) Config::get('mail.smtp.host', 'localhost'),
                (int) Config::get('mail.smtp.port', 465),
                (string) Config::get('mail.smtp.username', ''),
                (string) Config::get('mail.smtp.password', ''),
                (string) Config::get('mail.from_address', 'no-reply@example.com'),
                (string) Config::get('mail.from_name', Config::get('app.name', 'CRM Platform')),
                (string) Config::get('mail.smtp.encryption', 'ssl'),
            ),
            default => new LogMailer($this->basePath . '/storage/mail'),
        });

        // Phase 2/3: AuthService and what it needs. Repositories have
        // no constructor dependencies of their own, so `new` is fine
        // here (same as elsewhere in the app) rather than binding
        // each one individually.
        $this->container->singleton(OrganizationService::class, fn (Container $c) => new OrganizationService(
            $c->make(LoggerInterface::class),
            $c->make(EventBus::class),
            new OrganizationRepository(),
            $c->make(AuthorizationEngine::class),
            $this->basePath . '/storage',
        ));

        $this->container->singleton(AuthorizationEngine::class, fn () => new AuthorizationEngine(
            new RolePermissionRepository(),
            new UserPermissionGrantRepository()
        ));

        $this->container->singleton(AuthService::class, fn (Container $c) => new AuthService(
            $c->make(LoggerInterface::class),
            $c->make(EventBus::class),
            new UserRepository(),
            new AuthTokenRepository(),
            $c->make(MailerInterface::class),
            new LoginThrottle(new LoginAttemptRepository()),
            $c->make(OrganizationService::class),
            new RoleRepository(),
        ));

        $this->container->singleton(BankAccountService::class, fn () => new BankAccountService(
            new UserBankAccountRepository(),
            new BankRepository()
        ));

        $this->container->singleton(ContactCategoryService::class, fn (Container $c) => new ContactCategoryService(
            new ContactCategoryRepository(),
            $c->make(AuthorizationEngine::class)
        ));

        $this->container->singleton(\App\Application\Services\CompanyCategoryService::class, fn (Container $c) => new \App\Application\Services\CompanyCategoryService(
            new \App\Infrastructure\Repositories\CompanyCategoryRepository(),
            $c->make(AuthorizationEngine::class)
        ));

        $this->container->singleton(BankService::class, fn () => new BankService(
            new BankRepository()
        ));

        $this->container->singleton(UserManagementService::class, fn (Container $c) => new UserManagementService(
            $c->make(LoggerInterface::class),
            $c->make(EventBus::class),
            new UserRepository(),
            new RoleRepository(),
            $c->make(AuthorizationEngine::class),
            new UserPermissionGrantRepository(),
            new RolePermissionRepository(),
        ));

        $this->container->singleton(RoleService::class, fn (Container $c) => new RoleService(
            $c->make(LoggerInterface::class),
            $c->make(EventBus::class),
            new RoleRepository(),
            new RolePermissionRepository(),
            $c->make(AuthorizationEngine::class),
        ));

        $this->container->singleton(OrgStructureService::class, fn (Container $c) => new OrgStructureService(
            $c->make(LoggerInterface::class),
            $c->make(EventBus::class),
            new DepartmentRepository(),
            new TeamRepository(),
            $c->make(AuthorizationEngine::class),
        ));

        $this->container->singleton(ModuleService::class, fn (Container $c) => new ModuleService(
            $c->make(LoggerInterface::class),
            $c->make(EventBus::class),
            new ModuleRepository(),
            new OrganizationModuleRepository(),
            new UserModuleOverrideRepository(),
            new UserRepository(),
            $c->make(AuthorizationEngine::class),
        ));

        // Phase 5 — CRM Core shared infrastructure
        $this->container->singleton(TimelineService::class, fn () => new TimelineService(new TimelineEventRepository()));

        $this->container->singleton(TagService::class, fn (Container $c) => new TagService(
            new TagRepository(),
            $c->make(TimelineService::class),
        ));

        $this->container->singleton(NoteService::class, fn (Container $c) => new NoteService(
            new NoteRepository(),
            $c->make(TimelineService::class),
        ));

        $this->container->singleton(CustomFieldService::class, fn (Container $c) => new CustomFieldService(
            new CustomFieldDefinitionRepository(),
            new CustomFieldValueRepository(),
            $c->make(AuthorizationEngine::class),
        ));

        $this->container->singleton(AttachmentService::class, fn (Container $c) => new AttachmentService(
            new AttachmentRepository(),
            $c->make(TimelineService::class),
            $this->basePath . '/storage/uploads',
        ));

        // A generic, reusable storage-root string — Container has no
        // basePath() of its own (only Application does), so a module
        // that needs its own storage subdirectory (e.g. Knowledge's
        // file library) resolves this rather than needing basePath
        // threaded through to it some other way.
        $this->container->singleton('app.storage_root', fn () => $this->basePath . '/storage');

        // Phase 5 — CRM Core entities
        $this->container->singleton(CompanyService::class, fn (Container $c) => new CompanyService(
            new CompanyRepository(),
            new ContactRepository(),
            $c->make(TimelineService::class),
            $c->make(AuthorizationEngine::class),
        ));

        $this->container->singleton(ContactService::class, fn (Container $c) => new ContactService(
            new ContactRepository(),
            new ContactPhoneRepository(),
            new ContactEmailRepository(),
            new ContactSocialProfileRepository(),
            new ContactInterestRepository(),
            $c->make(TimelineService::class),
            $c->make(AuthorizationEngine::class),
        ));

        $this->container->singleton(LeadService::class, fn (Container $c) => new LeadService(
            new LeadRepository(),
            new ContactRepository(),
            new CompanyRepository(),
            $c->make(TimelineService::class),
            $c->make(AuthorizationEngine::class),
        ));

        $this->container->singleton(SearchService::class, fn (Container $c) => new SearchService(
            new ContactRepository(),
            new CompanyRepository(),
            new LeadRepository(),
            $c->make(AuthorizationEngine::class),
        ));

        // SMS providers, per country/language — like payment gateways.
        // A provider for another country = one SmsSenderInterface class +
        // one register() call here; its credential fields then show up in
        // Organization settings → SMS for organizations in that language.
        $this->container->singleton(SmsProviderRegistry::class, function () {
            $registry = new SmsProviderRegistry();
            $registry->register(new SmsProviderDefinition(
                key: 'kavenegar',
                label: 'Kavenegar (کاوه‌نگار)',
                languages: ['fa'],
                countries: ['IR'],
                fields: [
                    'api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true, 'hint' => 'sms_field.kavenegar_api_key_hint'],
                    'sender' => ['label' => 'sms_field.sender', 'required' => true, 'hint' => 'sms_field.kavenegar_sender_hint'],
                    'sender_international' => ['label' => 'sms_field.sender_international', 'hint' => 'sms_field.kavenegar_sender_international_hint'],
                ],
                factory: fn (array $c) => new KavenegarSmsSender(
                    (string) ($c['api_key'] ?? ''),
                    (string) ($c['sender'] ?? ''),
                    (string) ($c['sender_international'] ?? '')
                ),
                signupUrl: 'https://kavenegar.com/',
            ));

            return $registry;
        });

        // SMS from anywhere in the app: $container->make(SmsService::class).
        // Platform account = config sms.platform (Kavenegar from .env);
        // organizations may switch to their own account at /org/sms;
        // billing for platform sends is attached by the Notification
        // module (setBilling()).
        $this->container->singleton(SmsService::class, fn (Container $c) => new SmsService(
            $c->make(SmsProviderRegistry::class),
            (array) Config::get('sms.platform', []),
            new SmsLogRepository(),
            new OrganizationSmsSettingsRepository(),
            new OrganizationRepository()
        ));

        $this->container->singleton(ContactImportExportService::class, fn (Container $c) => new ContactImportExportService(
            new ContactRepository(),
            new CompanyRepository(),
            $c->make(CustomFieldService::class),
            $c->make(TagService::class),
            $c->make(TimelineService::class),
            $c->make(AuthorizationEngine::class),
            new ContactPhoneRepository(),
            new ContactEmailRepository(),
            new ContactCategoryRepository(),
            new ContactImportBatchRepository(),
            new ContactLinkRepository(),
            $this->basePath . '/storage/cache/contact-imports',
        ));
    }

    private function bootstrapModuleRegistry(): void
    {
        // Filesystem discovery only in Phase 1 — see ModuleRegistry docblock.
        ModuleRegistry::bootstrap($this->basePath . '/modules');
    }

    private function registerErrorHandling(): void
    {
        $handler = new ErrorHandler(
            $this->container->make(LoggerInterface::class),
            $this->container->make(TemplateEngine::class),
            (bool) Config::get('app.debug', false)
        );

        $this->container->instance(ErrorHandler::class, $handler);
        $handler->register();
    }

    public function loadRoutes(): void
    {
        $router = $this->router;
        $container = $this->container;

        require $this->basePath . '/routes/web.php';
        require $this->basePath . '/routes/api.php';

        $this->bootstrapModuleRoutes($container, $router);
    }

    /**
     * Per Phase 0 §63/§88 (Phase 4 — Module Manager): every discovered
     * module with a "bootstrap" class in its module.json gets
     * register($container) then routes($router) called, regardless of
     * its enabled state — ModuleMiddleware (wrapped around the routes
     * a module registers) is what actually gates access at request
     * time, per the Phase 0 §6 chain. A disabled/not-entitled module's
     * routes still exist, they just 404 when hit.
     */
    private function bootstrapModuleRoutes(Container $container, Router $router): void
    {
        foreach (ModuleRegistry::instance()->all() as $manifest) {
            if ($manifest->bootstrapClass === null || !class_exists($manifest->bootstrapClass)) {
                continue;
            }

            /** @var \App\Infrastructure\Modules\ModuleInterface $module */
            $module = new $manifest->bootstrapClass();
            $module->register($container);

            // New modules expose services through ProvidesServices
            // instead of adding another instanceof block below.
            $container->make(ModuleServiceRegistrar::class)->registerModule($container, $manifest->key, $module);

            $module->routes($router);

            // A narrow, explicit exception to "Core doesn't know about
            // modules": Notification (if installed) exposes its
            // dispatch service here so Internal Mail can optionally
            // trigger a notification on new mail without either module
            // hard-depending on the other — Internal Mail checks
            // $container->has(NotificationDispatchService::class)
            // before using it, so it works identically whether
            // Notification is installed or not.
            if ($module instanceof \Modules\Notification\NotificationModule) {
                $dispatchService = $module->dispatchService();
                if ($dispatchService !== null) {
                    $container->singleton(
                        \Modules\Notification\Services\NotificationDispatchService::class,
                        fn () => $dispatchService
                    );
                }

                $recipientService = $module->recipientService();
                if ($recipientService !== null) {
                    $container->singleton(
                        \Modules\Notification\Services\NotificationRecipientService::class,
                        fn () => $recipientService
                    );
                }
            }

            // Same lazy, non-hard-dependency pattern for the three
            // other modules the Dashboard optionally reads from —
            // Core never references these classes except through a
            // container has()/make() check, exactly like Notification
            // above.
            if ($module instanceof \Modules\Wallet\WalletModule) {
                $walletService = $module->walletService();
                if ($walletService !== null) {
                    $container->singleton(\Modules\Wallet\Services\WalletService::class, fn () => $walletService);
                }

                $walletSettingsService = $module->walletSettingsService();
                if ($walletSettingsService !== null) {
                    $container->singleton(\Modules\Wallet\Services\WalletSettingsService::class, fn () => $walletSettingsService);
                }
            }

            if ($module instanceof \Modules\Sales\SalesModule) {
                $taskService = $module->taskService();
                if ($taskService !== null) {
                    $container->singleton(\Modules\Sales\Services\TaskService::class, fn () => $taskService);
                }
            }

            if ($module instanceof \Modules\InternalMail\InternalMailModule) {
                $messageService = $module->messageService();
                if ($messageService !== null) {
                    $container->singleton(\Modules\InternalMail\Services\MessageService::class, fn () => $messageService);
                }
            }

            if ($module instanceof \Modules\Support\SupportModule) {
                $ticketService = $module->ticketService();
                if ($ticketService !== null) {
                    $container->singleton(\Modules\Support\Services\TicketService::class, fn () => $ticketService);
                }
            }

            if ($module instanceof \Modules\Finance\FinanceModule) {
                $productService = $module->productService();
                if ($productService !== null) {
                    $container->singleton(\Modules\Finance\Services\ProductService::class, fn () => $productService);
                }

                $proformaService = $module->proformaService();
                if ($proformaService !== null) {
                    $container->singleton(\Modules\Finance\Services\ProformaService::class, fn () => $proformaService);
                }

                $invoiceService = $module->invoiceService();
                if ($invoiceService !== null) {
                    $container->singleton(\Modules\Finance\Services\InvoiceService::class, fn () => $invoiceService);
                }

                $paymentService = $module->paymentService();
                if ($paymentService !== null) {
                    $container->singleton(\Modules\Finance\Services\PaymentService::class, fn () => $paymentService);
                }
            }

            if ($module instanceof \Modules\Followups\FollowupsModule) {
                $followUpService = $module->followUpService();
                if ($followUpService !== null) {
                    $container->singleton(\Modules\Followups\Services\FollowUpService::class, fn () => $followUpService);
                }
            }
        }
    }

    public function handle(Request $request): Response
    {
        // Defensive per-request reset: on ordinary shared hosting each
        // request is a fresh PHP process so these statics start empty
        // anyway, but resetting explicitly keeps this correct even if
        // a future deployment target uses a persistent worker model
        // (Swoole/RoadRunner) where the process outlives one request.
        OrganizationContext::reset();
        CurrentUser::reset();
        Locale::reset();
        $this->container->make(ModuleGate::class)->reset();

        // Resolves "who is logged in" (if anyone) for EVERY request,
        // not just routes that require login. This used to only
        // happen inside AuthMiddleware, which is only attached to
        // protected routes (like /profile) — so a public page like
        // the homepage never populated CurrentUser at all, and the
        // layout's "logged in as X / Log out" nav silently showed the
        // guest state even for a logged-in visitor. AuthMiddleware
        // now just checks CurrentUser::isGuest() (see its docblock)
        // instead of re-resolving the user itself.
        $this->resolveCurrentUser();

        // Same reasoning, one step further (Phase 3): resolve
        // OrganizationContext from the now-known CurrentUser for
        // EVERY request too, rather than depending on every route
        // remembering to attach TenantMiddleware. A Super Admin (no
        // organization_id) simply never gets a context set here —
        // per Phase 0 §12–13, their cross-org access must go through
        // the explicit, conspicuous SuperAdminContext::run() wrapper
        // instead, never an implicit global bypass.
        $this->resolveOrganizationContext();

        // One step further again: resolve which language/direction
        // this request renders in, from the now-known CurrentUser (a
        // logged-in user's own `language` — always populated at
        // registration, see AuthService::register()) or, for a guest
        // who hasn't logged in yet, the app's configured default.
        $this->resolveLocale();

        try {
            return $this->router->dispatch($request);
        } catch (\Throwable $e) {
            /** @var ErrorHandler $handler */
            $handler = $this->container->make(ErrorHandler::class);

            return $handler->handle($e, $request);
        }
    }

    private function resolveCurrentUser(): void
    {
        $userId = Session::userId();

        if ($userId === null) {
            return;
        }

        $user = (new UserRepository())->findById($userId);

        if ($user === null || $user['status'] !== 'active') {
            // Session pointed at a user that no longer exists or was
            // deactivated — don't leave a dead session around.
            Session::logout();

            return;
        }

        CurrentUser::set($user);
    }

    private function resolveOrganizationContext(): void
    {
        $user = CurrentUser::user();

        if ($user !== null && $user['organization_id'] !== null) {
            OrganizationContext::set((int) $user['organization_id']);
        }
    }

    private function resolveLocale(): void
    {
        $user = CurrentUser::user();
        $locale = $user['language'] ?? Session::guestLocale() ?? Config::get('app.default_language', 'en');

        if (!in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
            $locale = 'en';
        }

        Locale::set(new Translator($locale, $this->basePath . '/lang'));
    }

    public function run(): void
    {
        Session::start();

        $request = Request::fromGlobals();
        $response = $this->handle($request);
        $response->send();
    }
}
