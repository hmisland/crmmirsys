<?php

declare(strict_types=1);

use App\Application\Controllers\AuthController;
use App\Application\Controllers\BankAccountController;
use App\Application\Controllers\DashboardController;
use App\Application\Controllers\ExchangeRateController;
use App\Application\Controllers\LanguageController;
use App\Application\Controllers\CompanyController;
use App\Application\Controllers\ContactController;
use App\Application\Controllers\ContactImportController;
use App\Application\Controllers\CustomFieldAdminController;
use App\Application\Controllers\LeadController;
use App\Application\Controllers\ModuleAdminController;
use App\Application\Controllers\OrganizationController;
use App\Application\Controllers\OrganizationModuleAdminController;
use App\Application\Controllers\OrgModulesController;
use App\Application\Controllers\OrgStructureController;
use App\Application\Controllers\RecordSupportController;
use App\Application\Controllers\RoleController;
use App\Application\Controllers\SearchController;
use App\Application\Controllers\SuperAdminController;
use App\Application\Controllers\ThemeAssetController;
use App\Application\Controllers\UserManagementController;
use App\Application\Services\AttachmentService;
use App\Application\Services\AuthService;
use App\Application\Services\BankAccountService;
use App\Application\Services\BankService;
use App\Application\Services\CompanyService;
use App\Application\Services\ContactImportExportService;
use App\Application\Services\ContactCategoryService;
use App\Application\Services\ContactService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\ExchangeRateService;
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
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\ExchangeRateRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * @var Router $router
 * @var Container $container
 */

$authController = new AuthController(
    $container->make(TemplateEngine::class),
    $container->make(AuthService::class),
    $container,
    $container->make(BankAccountService::class),
    $container->make(BankService::class)
);

$languageController = new LanguageController($container->make(TemplateEngine::class), new UserRepository());
$router->get('/lang/switch', fn (Request $r) => $languageController->switch($r));

$bankAccountController = new BankAccountController(
    $container->make(TemplateEngine::class),
    $container->make(BankAccountService::class)
);

$organizationController = new OrganizationController(
    $container->make(TemplateEngine::class),
    $container->make(OrganizationService::class),
    $container->make(\App\Application\Services\SmsService::class)
);

$userManagementController = new UserManagementController(
    $container->make(TemplateEngine::class),
    $container->make(UserManagementService::class),
    $container->make(RoleService::class)
);

$roleController = new RoleController(
    $container->make(TemplateEngine::class),
    $container->make(RoleService::class)
);

$orgStructureController = new OrgStructureController(
    $container->make(TemplateEngine::class),
    $container->make(OrgStructureService::class)
);

$superAdminController = new SuperAdminController(
    $container->make(TemplateEngine::class),
    new \App\Infrastructure\Repositories\OrganizationRepository(),
    new \App\Infrastructure\Repositories\UserRepository()
);

$moduleAdminController = new ModuleAdminController(
    $container->make(TemplateEngine::class),
    $container->make(ModuleService::class)
);

$organizationModuleAdminController = new OrganizationModuleAdminController(
    $container->make(TemplateEngine::class),
    $container->make(ModuleService::class),
    new \App\Infrastructure\Repositories\OrganizationRepository()
);

$orgModulesController = new OrgModulesController(
    $container->make(TemplateEngine::class),
    $container->make(ModuleService::class)
);

$contactController = new ContactController(
    $container->make(TemplateEngine::class),
    $container->make(ContactService::class),
    $container->make(NoteService::class),
    $container->make(TagService::class),
    $container->make(TimelineService::class),
    $container->make(CustomFieldService::class),
    new CompanyRepository(),
    $container->make(AttachmentService::class),
    $container->make(ContactCategoryService::class),
    $container
);

$contactImportController = new ContactImportController(
    $container->make(TemplateEngine::class),
    $container->make(ContactImportExportService::class),
    $container->make(ContactCategoryService::class),
    new CompanyRepository()
);

$companyController = new CompanyController(
    $container->make(TemplateEngine::class),
    $container->make(CompanyService::class),
    $container->make(NoteService::class),
    $container->make(TagService::class),
    $container->make(TimelineService::class),
    $container->make(CustomFieldService::class),
    new ContactRepository(),
    $container->make(AttachmentService::class),
    $container->make(\App\Application\Services\CompanyCategoryService::class),
    $container->make(ContactService::class)
);

$leadController = new LeadController(
    $container->make(TemplateEngine::class),
    $container->make(LeadService::class),
    $container->make(NoteService::class),
    $container->make(TagService::class),
    $container->make(TimelineService::class),
    $container->make(\App\Infrastructure\Authorization\AuthorizationEngine::class)
);

$recordSupportController = new RecordSupportController(
    $container->make(TemplateEngine::class),
    $container->make(NoteService::class),
    $container->make(TagService::class),
    $container->make(AttachmentService::class),
    $container->make(\App\Infrastructure\Records\RecordTypeRegistry::class)
);

$customFieldAdminController = new CustomFieldAdminController(
    $container->make(TemplateEngine::class),
    $container->make(CustomFieldService::class)
);

$searchController = new SearchController(
    $container->make(TemplateEngine::class),
    $container->make(SearchService::class),
    $container
);

// Theme static assets (see ThemeAssetController docblock for why this
// exists instead of a symlink).
$router->get('/assets/themes/{theme}/{path*}', function (Request $request) use ($container) {
    $controller = new ThemeAssetController($container->make(\App\Infrastructure\Theme\ThemeLoader::class));

    return $controller($request);
});

// Phase 1 landing page: proves the whole pipeline (routing, theming,
// templates, module discovery) works end to end. Real CRM Core routes
// (dashboard, contacts, ...) are added starting Phase 5.
$dashboardController = new DashboardController(
    $container->make(TemplateEngine::class),
    $container,
    new ContactRepository(),
    new CompanyRepository(),
    new CurrencyRepository()
);
$router->get('/', fn (Request $r) => $dashboardController->index($r));

// --- Auth (Phase 2) ---------------------------------------------------

$router->get('/login', fn (Request $r) => $authController->showLogin($r));
$router->post('/login', fn (Request $r) => $authController->login($r), [new CsrfMiddleware()]);

$router->get('/register', fn (Request $r) => $authController->showRegister($r));
$router->post('/register', fn (Request $r) => $authController->register($r), [new CsrfMiddleware()]);

$router->post('/logout', fn (Request $r) => $authController->logout($r), [new CsrfMiddleware()]);

$router->get('/email/verify/{token}', fn (Request $r) => $authController->verifyEmail($r));

$router->get('/password/forgot', fn (Request $r) => $authController->showForgotPassword($r));
$router->post('/password/forgot', fn (Request $r) => $authController->forgotPassword($r), [new CsrfMiddleware()]);

$router->get('/password/reset/{token}', fn (Request $r) => $authController->showResetPassword($r));
$router->post('/password/reset/{token}', fn (Request $r) => $authController->resetPassword($r), [new CsrfMiddleware()]);

// Requires a logged-in session (see AuthMiddleware) — redirects to
// /login for a guest rather than erroring.
$router->get('/profile', fn (Request $r) => $authController->profile($r), [new AuthMiddleware()]);
$router->post('/profile', fn (Request $r) => $authController->updateProfile($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/profile/bank-accounts', fn (Request $r) => $bankAccountController->add($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/profile/bank-accounts/{id}/delete', fn (Request $r) => $bankAccountController->delete($r), [new AuthMiddleware(), new CsrfMiddleware()]);

// --- Organization admin (Phase 3) --------------------------------------
// Every route requires login (AuthMiddleware); the view-level route in
// each sub-area also requires the matching "view" permission
// (RbacMiddleware) as a fast pre-check — create/edit/delete actions
// are re-checked with the specific permission they need inside the
// Service itself (defense in depth, see RbacMiddleware's docblock).

$orgSmsController = new \App\Application\Controllers\OrgSmsController(
    $container->make(TemplateEngine::class),
    $container->make(\App\Application\Services\SmsService::class)
);

$router->group('/org', [new AuthMiddleware()], function (Router $router) use (
    $organizationController,
    $userManagementController,
    $roleController,
    $orgStructureController,
    $orgModulesController,
    $customFieldAdminController,
    $orgSmsController
) {
    $router->get('/settings', fn (Request $r) => $organizationController->show($r), [new RbacMiddleware('organization.view')]);
    $router->post('/settings', fn (Request $r) => $organizationController->update($r), [new RbacMiddleware('organization.edit'), new CsrfMiddleware()]);

    // SMS: platform account (paid from credit) or the organization's own Kavenegar key.
    $router->get('/sms', fn (Request $r) => $orgSmsController->index($r), [new RbacMiddleware('organization.edit')]);
    $router->post('/sms', fn (Request $r) => $orgSmsController->save($r), [new RbacMiddleware('organization.edit'), new CsrfMiddleware()]);
    $router->post('/sms/test', fn (Request $r) => $orgSmsController->test($r), [new RbacMiddleware('organization.edit'), new CsrfMiddleware()]);
    $router->get('/logo', fn (Request $r) => $organizationController->logo($r));

    $router->get('/users', fn (Request $r) => $userManagementController->index($r), [new RbacMiddleware('users.view')]);
    $router->post('/users', fn (Request $r) => $userManagementController->create($r), [new CsrfMiddleware()]);
    $router->post('/users/{id}/role', fn (Request $r) => $userManagementController->updateRole($r), [new CsrfMiddleware()]);
    $router->post('/users/{id}/grants', fn (Request $r) => $userManagementController->updatePermissionGrant($r), [new CsrfMiddleware()]);
    $router->post('/users/{id}/activate', fn (Request $r) => $userManagementController->activate($r), [new CsrfMiddleware()]);
    $router->post('/users/{id}/deactivate', fn (Request $r) => $userManagementController->deactivate($r), [new CsrfMiddleware()]);

    $router->get('/roles', fn (Request $r) => $roleController->index($r), [new RbacMiddleware('roles.view')]);
    $router->post('/roles', fn (Request $r) => $roleController->create($r), [new CsrfMiddleware()]);
    $router->post('/roles/{id}', fn (Request $r) => $roleController->update($r), [new CsrfMiddleware()]);
    $router->post('/roles/{id}/delete', fn (Request $r) => $roleController->delete($r), [new CsrfMiddleware()]);

    $router->get('/structure', fn (Request $r) => $orgStructureController->index($r));
    $router->post('/structure/departments', fn (Request $r) => $orgStructureController->createDepartment($r), [new CsrfMiddleware()]);
    $router->post('/structure/departments/{id}/delete', fn (Request $r) => $orgStructureController->deleteDepartment($r), [new CsrfMiddleware()]);
    $router->post('/structure/teams', fn (Request $r) => $orgStructureController->createTeam($r), [new CsrfMiddleware()]);
    $router->post('/structure/teams/{id}/delete', fn (Request $r) => $orgStructureController->deleteTeam($r), [new CsrfMiddleware()]);

    $router->get('/modules', fn (Request $r) => $orgModulesController->index($r));
    $router->get('/modules/{id}/access', fn (Request $r) => $orgModulesController->manageAccess($r));
    $router->post('/modules/{id}/access/{userId}/restrict', fn (Request $r) => $orgModulesController->restrictUser($r), [new CsrfMiddleware()]);
    $router->post('/modules/{id}/access/{userId}/allow', fn (Request $r) => $orgModulesController->allowUser($r), [new CsrfMiddleware()]);

    $router->get('/custom-fields', fn (Request $r) => $customFieldAdminController->index($r));
    $router->post('/custom-fields', fn (Request $r) => $customFieldAdminController->create($r), [new CsrfMiddleware()]);
    $router->post('/custom-fields/{id}/delete', fn (Request $r) => $customFieldAdminController->delete($r), [new CsrfMiddleware()]);
});

// --- Super Admin (Phase 3/4) --------------------------------------------

$router->get('/admin/organizations', fn (Request $r) => $superAdminController->index($r), [new AuthMiddleware()]);

$router->get('/admin/modules', fn (Request $r) => $moduleAdminController->index($r), [new AuthMiddleware()]);
$router->post('/admin/modules/sync', fn (Request $r) => $moduleAdminController->sync($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin/modules/{id}/enable', fn (Request $r) => $moduleAdminController->enable($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin/modules/{id}/disable', fn (Request $r) => $moduleAdminController->disable($r), [new AuthMiddleware(), new CsrfMiddleware()]);

$router->get('/admin/organizations/{id}/modules', fn (Request $r) => $organizationModuleAdminController->index($r), [new AuthMiddleware()]);
$router->post('/admin/organizations/{id}/modules/{moduleId}/enable', fn (Request $r) => $organizationModuleAdminController->enable($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin/organizations/{id}/modules/{moduleId}/disable', fn (Request $r) => $organizationModuleAdminController->disable($r), [new AuthMiddleware(), new CsrfMiddleware()]);

// Exchange rates (Core, shared with Wallet) — reachable regardless of
// Finance's entitlement, same reasoning as Wallet's own platform
// settings: a Super Admin must be able to set these before any
// organization has Finance enabled at all.
$exchangeRateController = new ExchangeRateController(
    $container->make(TemplateEngine::class),
    new ExchangeRateService(new ExchangeRateRepository()),
    new CurrencyRepository()
);
$router->get('/admin/exchange-rates', fn (Request $r) => $exchangeRateController->index($r), [new AuthMiddleware()]);
$router->post('/admin/exchange-rates', fn (Request $r) => $exchangeRateController->update($r), [new AuthMiddleware(), new CsrfMiddleware()]);

// SMS (Kavenegar) — Super Admin status, credit, test send and log.
$smsAdminController = new \App\Application\Controllers\SmsAdminController(
    $container->make(TemplateEngine::class),
    $container->make(\App\Application\Services\SmsService::class)
);
$router->get('/admin/sms', fn (Request $r) => $smsAdminController->index($r), [new AuthMiddleware()]);
$router->post('/admin/sms/test', fn (Request $r) => $smsAdminController->test($r), [new AuthMiddleware(), new CsrfMiddleware()]);

// Announcements — Super Admin → org admins (one-way), read from the top-bar bell.
$announcementController = new \App\Application\Controllers\AdminAnnouncementController(
    $container->make(TemplateEngine::class),
    new \App\Infrastructure\Repositories\AdminAnnouncementRepository()
);
$router->get('/admin/announcements', fn (Request $r) => $announcementController->index($r), [new AuthMiddleware()]);
$router->post('/admin/announcements', fn (Request $r) => $announcementController->send($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->get('/admin/announcements/{id}', fn (Request $r) => $announcementController->show($r), [new AuthMiddleware()]);
$router->post('/admin/announcements/{id}/delete', fn (Request $r) => $announcementController->destroy($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->get('/announcements', fn (Request $r) => $announcementController->inbox($r), [new AuthMiddleware()]);
$router->post('/announcements/{id}/seen', fn (Request $r) => $announcementController->markSeen($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/announcements/{id}/delete', fn (Request $r) => $announcementController->remove($r), [new AuthMiddleware(), new CsrfMiddleware()]);

// Help center — every signed-in user reads; only the Super Admin manages
// (checked inside the controller). /admin/help/* is registered apart
// from /help/{slug} so a slug can never shadow a management route.
$helpController = new \App\Application\Controllers\HelpController(
    $container->make(TemplateEngine::class),
    new \App\Infrastructure\Repositories\HelpArticleRepository()
);
$router->get('/help', fn (Request $r) => $helpController->index($r), [new AuthMiddleware()]);
$router->get('/help/{slug}', fn (Request $r) => $helpController->show($r), [new AuthMiddleware()]);
$router->get('/admin/help/create', fn (Request $r) => $helpController->create($r), [new AuthMiddleware()]);
$router->post('/admin/help', fn (Request $r) => $helpController->store($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->get('/admin/help/{id}/edit', fn (Request $r) => $helpController->edit($r), [new AuthMiddleware()]);
$router->post('/admin/help/{id}', fn (Request $r) => $helpController->update($r), [new AuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin/help/{id}/delete', fn (Request $r) => $helpController->destroy($r), [new AuthMiddleware(), new CsrfMiddleware()]);

// --- CRM Core (Phase 5) --------------------------------------------------
// Every route requires login; the list ("view") route of each entity
// also gets a fast RbacMiddleware pre-check — create/edit/delete are
// re-checked inside the Service itself (same defense-in-depth pattern
// as Phase 3's /org routes).

$router->group('/contacts', [new AuthMiddleware()], function (Router $router) use ($contactController, $contactImportController) {
    $router->get('', fn (Request $r) => $contactController->index($r), [new RbacMiddleware('contacts.view')]);
    $router->get('/create', fn (Request $r) => $contactController->showCreate($r));
    $router->post('', fn (Request $r) => $contactController->create($r), [new CsrfMiddleware()]);

    // Registered before /{id} — otherwise the wildcard route below
    // would greedily match "export"/"import" as if they were a
    // numeric id and these would never be reached.
    $router->get('/export', fn (Request $r) => $contactImportController->export($r), [new RbacMiddleware('contacts.export')]);
    $router->get('/import', fn (Request $r) => $contactImportController->showImport($r), [new RbacMiddleware('contacts.import')]);
    $router->post('/import/preview', fn (Request $r) => $contactImportController->preview($r), [new RbacMiddleware('contacts.import'), new CsrfMiddleware()]);
    $router->post('/import/commit', fn (Request $r) => $contactImportController->commit($r), [new RbacMiddleware('contacts.import'), new CsrfMiddleware()]);
    $router->post('/import/rollback', fn (Request $r) => $contactImportController->rollback($r), [new RbacMiddleware('contacts.import'), new CsrfMiddleware()]);

    $router->get('/categories', fn (Request $r) => $contactController->categoriesPage($r), [new RbacMiddleware('contacts.manage_categories')]);
    $router->post('/categories', fn (Request $r) => $contactController->createCategory($r), [new CsrfMiddleware()]);
    $router->post('/categories/{id}/delete', fn (Request $r) => $contactController->deleteCategory($r), [new CsrfMiddleware()]);

    $router->get('/{id}', fn (Request $r) => $contactController->show($r));
    $router->get('/{id}/edit', fn (Request $r) => $contactController->showEdit($r));
    $router->get('/{id}/related/{type}', fn (Request $r) => $contactController->related($r));
    $router->post('/bulk-delete', fn (Request $r) => $contactController->bulkDelete($r), [new CsrfMiddleware()]);
    $router->post('/{id}', fn (Request $r) => $contactController->update($r), [new CsrfMiddleware()]);
    $router->post('/{id}/delete', fn (Request $r) => $contactController->delete($r), [new CsrfMiddleware()]);
});

$router->group('/companies', [new AuthMiddleware()], function (Router $router) use ($companyController) {
    $router->get('', fn (Request $r) => $companyController->index($r), [new RbacMiddleware('companies.view')]);
    $router->get('/create', fn (Request $r) => $companyController->showCreate($r));
    $router->post('', fn (Request $r) => $companyController->create($r), [new CsrfMiddleware()]);
    $router->get('/categories', fn (Request $r) => $companyController->categoriesPage($r), [new RbacMiddleware('companies.manage_categories')]);
    $router->post('/categories', fn (Request $r) => $companyController->createCategory($r), [new CsrfMiddleware()]);
    $router->post('/categories/{id}/delete', fn (Request $r) => $companyController->deleteCategory($r), [new CsrfMiddleware()]);
    $router->get('/{id}', fn (Request $r) => $companyController->show($r));
    $router->get('/{id}/edit', fn (Request $r) => $companyController->showEdit($r));
    $router->post('/bulk-delete', fn (Request $r) => $companyController->bulkDelete($r), [new CsrfMiddleware()]);
    $router->post('/{id}', fn (Request $r) => $companyController->update($r), [new CsrfMiddleware()]);
    $router->post('/{id}/delete', fn (Request $r) => $companyController->delete($r), [new CsrfMiddleware()]);
});

$router->group('/leads', [new AuthMiddleware()], function (Router $router) use ($leadController) {
    $router->get('', fn (Request $r) => $leadController->index($r), [new RbacMiddleware('leads.view')]);
    $router->get('/create', fn (Request $r) => $leadController->showCreate($r));
    $router->post('', fn (Request $r) => $leadController->create($r), [new CsrfMiddleware()]);
    $router->get('/{id}', fn (Request $r) => $leadController->show($r));
    $router->get('/{id}/edit', fn (Request $r) => $leadController->showEdit($r));
    $router->post('/bulk-delete', fn (Request $r) => $leadController->bulkDelete($r), [new CsrfMiddleware()]);
    $router->post('/{id}', fn (Request $r) => $leadController->update($r), [new CsrfMiddleware()]);
    $router->post('/{id}/delete', fn (Request $r) => $leadController->delete($r), [new CsrfMiddleware()]);
    $router->post('/{id}/convert', fn (Request $r) => $leadController->convert($r), [new CsrfMiddleware()]);
});

// Shared notes/tags/attachments for contacts, companies, and leads —
// {type} is validated against an allow-list inside the controller.
$router->group('/records/{type}/{id}', [new AuthMiddleware()], function (Router $router) use ($recordSupportController) {
    $router->post('/notes', fn (Request $r) => $recordSupportController->addNote($r), [new CsrfMiddleware()]);
    $router->post('/notes/{noteId}/delete', fn (Request $r) => $recordSupportController->deleteNote($r), [new CsrfMiddleware()]);
    $router->post('/tags', fn (Request $r) => $recordSupportController->addTag($r), [new CsrfMiddleware()]);
    $router->post('/tags/{tagId}/delete', fn (Request $r) => $recordSupportController->removeTag($r), [new CsrfMiddleware()]);
    $router->post('/attachments', fn (Request $r) => $recordSupportController->uploadAttachment($r), [new CsrfMiddleware()]);
    $router->get('/attachments/{attachmentId}/download', fn (Request $r) => $recordSupportController->downloadAttachment($r));
    $router->post('/attachments/{attachmentId}/delete', fn (Request $r) => $recordSupportController->deleteAttachment($r), [new CsrfMiddleware()]);
});

$router->get('/search', fn (Request $r) => $searchController->index($r), [new AuthMiddleware()]);

// --- System (temporary, Phase 2 stopgap — see docs/phase2-authentication.md) ---
//
// No SSH on the target shared host means no migration CLI. This lets
// core migrations run via a secret token in the URL instead. Requires
// MIGRATE_TOKEN to be set in .env; 404s (not 403 — don't reveal the
// route exists) whenever it's empty or the token doesn't match.
// Idempotent (Migrator skips already-applied migrations), so it's
// low-risk to leave reachable, but rotate/clear MIGRATE_TOKEN once
// you're done running migrations for extra safety.
$router->get('/system/migrate', function (Request $request) {
    $token = (string) Config::get('app.migrate_token', '');

    if ($token === '' || !hash_equals($token, (string) $request->query('token', ''))) {
        throw new \App\Core\Exceptions\NotFoundException();
    }

    $pdo = \App\Infrastructure\Database\Connection::pdo();

    $coreMigrator = new \App\Infrastructure\Database\Migrator(
        $pdo,
        dirname(__DIR__) . '/database/migrations',
        'migrations'
    );
    $applied = ['core' => $coreMigrator->run()];

    // Phase 4: each installed module with its own Migrations/ folder
    // gets run too, tracked separately in module_migrations per
    // Phase 0 §31 — independent of core, and of each other.
    foreach (\App\Infrastructure\Modules\ModuleRegistry::instance()->all() as $manifest) {
        if (!is_dir($manifest->path . '/Migrations')) {
            continue;
        }

        $moduleMigrator = new \App\Infrastructure\Database\Migrator(
            $pdo,
            $manifest->path . '/Migrations',
            'module_migrations',
            $manifest->key
        );
        $applied['modules'][$manifest->key] = $moduleMigrator->run();
    }

    return Response::json(['data' => ['applied' => $applied]]);
});
