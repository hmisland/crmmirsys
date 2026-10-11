<?php

declare(strict_types=1);

/**
 * Boots the real Application (all real modules, real routes) against
 * the LOCAL database and checks that Contacts/Companies/Leads pages and
 * Notes/Tags/Attachments/Timeline still work with the new extension
 * points in place.
 *
 * Safety: refuses to run unless APP_ENV=local and the database is
 * crm_local. Every test runs inside one transaction that is rolled
 * back at the end, so no data is left behind.
 */

use App\Application\Controllers\RecordSupportController;
use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Core\Application;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\NoteRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\TagRepository;
use App\Infrastructure\Repositories\TimelineEventRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;

$ctx = new \stdClass();
$ctx->ready = false;
$ctx->reason = '';

$setup = function () use ($ctx): void {
    if ($ctx->ready) {
        return;
    }
    if ($ctx->reason !== '') {
        throw new LiteSkip($ctx->reason);
    }

    try {
        $app = new Application(dirname(__DIR__, 2));
        $app->loadRoutes();
        restore_error_handler();
        restore_exception_handler();

        if (Config::get('app.env') !== 'local' || ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE')) !== 'crm_local') {
            throw new \RuntimeException('not the local crm_local database');
        }

        $pdo = Connection::pdo();
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'crm_local') {
            throw new \RuntimeException('connected database is not crm_local');
        }

        $userId = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.`key` = 'org_admin' AND u.organization_id IS NOT NULL AND u.status = 'active'
             ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        if ($userId === false) {
            throw new \RuntimeException('no active org_admin user in crm_local');
        }
    } catch (\Throwable $e) {
        $ctx->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($ctx->reason);
    }

    $user = (new UserRepository())->findById((int) $userId);
    CurrentUser::set($user);
    OrganizationContext::set((int) $user['organization_id']);
    $pdo->beginTransaction();
    register_shutdown_function(function () use ($pdo) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    });

    $ctx->app = $app;
    $ctx->c = $app->container;
    $ctx->user = $user;
    $ctx->contactId = (new ContactRepository())->create(['first_name' => 'LiteTest', 'last_name' => 'Contact', 'owner_id' => $user['id']]);
    $ctx->companyId = (new CompanyRepository())->create(['name' => 'LiteTest Company', 'owner_id' => $user['id']]);
    $ctx->leadId = (new LeadRepository())->create(['first_name' => 'LiteTest', 'last_name' => 'Lead', 'owner_id' => $user['id']]);
    $ctx->ready = true;
};

$read = function (Response $r, string $prop): mixed {
    $p = new \ReflectionProperty(Response::class, $prop);

    return $p->getValue($r);
};

$get = function (string $path) use ($ctx, $read): array {
    $response = $ctx->app->router->dispatch(new Request('GET', $path, [], [], [], []));

    return [$read($response, 'status'), $read($response, 'body')];
};

$controller = function () use ($ctx): RecordSupportController {
    return new RecordSupportController(
        $ctx->c->make(TemplateEngine::class),
        $ctx->c->make(NoteService::class),
        $ctx->c->make(TagService::class),
        $ctx->c->make(AttachmentService::class),
        $ctx->c->make(RecordTypeRegistry::class),
    );
};

$post = function (string $type, int $id, array $body = [], array $extra = []): Request {
    $request = new Request('POST', "/records/{$type}/{$id}", [], $body, [], []);
    $request->setAttribute('type', $type);
    $request->setAttribute('id', (string) $id);
    foreach ($extra as $k => $v) {
        $request->setAttribute($k, $v);
    }

    return $request;
};

$subjects = fn () => ['contact' => $ctx->contactId, 'company' => $ctx->companyId, 'lead' => $ctx->leadId];

return [
    'real Application boots every installed module with the new hook' => function () use ($setup, $ctx) {
        $setup();
        $keys = array_map(fn ($m) => $m->key, ModuleRegistry::instance()->all());
        LiteAssert::true(in_array('sales', $keys, true) && in_array('followups', $keys, true), 'modules discovered');
    },

    'existing module service bindings are unchanged' => function () use ($setup, $ctx) {
        $setup();
        foreach ([
            \Modules\Sales\Services\TaskService::class,
            \Modules\Finance\Services\InvoiceService::class,
            \Modules\Followups\Services\FollowUpService::class,
            \Modules\Support\Services\TicketService::class,
            \Modules\Wallet\Services\WalletService::class,
        ] as $service) {
            LiteAssert::true($ctx->c->has($service), "{$service} still bound");
        }
    },

    'registries are bound; Core types registered and viewable by an org admin' => function () use ($setup, $ctx) {
        $setup();
        $types = $ctx->c->make(RecordTypeRegistry::class);
        LiteAssert::true($ctx->c->make(RelatedPanelRegistry::class) instanceof RelatedPanelRegistry);
        foreach (['contact', 'company', 'lead'] as $key) {
            LiteAssert::true($types->canView($key, $ctx->user), "{$key} viewable");
        }
        // Step 2.2: the Contracts module registers `contract` itself (was "not registered" in Step 1).
        LiteAssert::same('contracts', $types->get('contract')?->module, 'contract type owned by the Contracts module');
        // Phase 5 Step 3: the Projects module registers `project` itself (was "not registered yet").
        LiteAssert::same('projects', $types->get('project')?->module, 'project type owned by the Projects module');
    },

    'ModuleGate agrees with ModuleAccessResolver for every real module' => function () use ($setup, $ctx) {
        $setup();
        $gate = $ctx->c->make(ModuleGate::class);
        $resolver = new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository());
        foreach (ModuleRegistry::instance()->all() as $m) {
            LiteAssert::same($resolver->resolvedForUser($m->key, $ctx->user), $gate->allows($ctx->user, $m->key, null), "gate == resolver for {$m->key}");
        }
    },

    'contact, company and lead pages still render (200)' => function () use ($setup, $ctx, $get) {
        $setup();
        foreach (['/contacts/' . $ctx->contactId => 'LiteTest', '/companies/' . $ctx->companyId => 'LiteTest Company', '/leads/' . $ctx->leadId => 'LiteTest'] as $path => $text) {
            [$status, $body] = $get($path);
            LiteAssert::same(200, $status, "status for {$path}");
            LiteAssert::contains($text, $body, "content of {$path}");
            LiteAssert::notContains('data-related-panel', $body, "no panels by default on {$path}");
        }
    },

    'deal page still renders (200) with the panel slot' => function () use ($setup, $ctx, $get) {
        $setup();
        $dealId = Connection::pdo()->query('SELECT id FROM deals WHERE organization_id = ' . (int) $ctx->user['organization_id'] . ' ORDER BY id LIMIT 1')->fetchColumn();
        if ($dealId === false) {
            throw new LiteSkip('no deal in this organization of crm_local');
        }
        [$status] = $get('/deals/' . $dealId);
        LiteAssert::same(200, $status);
    },

    'a registered panel appears on the record page; an unavailable one does not' => function () use ($setup, $ctx, $get) {
        $setup();
        $panels = $ctx->c->make(RelatedPanelRegistry::class);
        $panels->register(new RelatedPanel('lead', 'lite.core', null, 'common.notes', fn (array $s) => '<p>LITE-PANEL-' . $s['id'] . '</p>'));
        $panels->register(new RelatedPanel('lead', 'lite.missing', 'no_such_module', 'common.notes', fn () => '<p>LITE-HIDDEN</p>'));

        [$status, $body] = $get('/leads/' . $ctx->leadId);
        LiteAssert::same(200, $status);
        LiteAssert::contains('LITE-PANEL-' . $ctx->leadId, $body);
        LiteAssert::contains('data-related-panel="lite.core"', $body);
        LiteAssert::notContains('LITE-HIDDEN', $body, 'panel of a non-installed module');
    },

    'notes: add and delete on contact/company/lead, redirect back like before' => function () use ($setup, $ctx, $controller, $post, $read, $subjects) {
        $setup();
        foreach ($subjects() as $type => $id) {
            $response = $controller()->addNote($post($type, $id, ['body' => "lite note {$type}"]));
            $location = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response)['Location'] ?? '';
            $expected = ['contact' => '/contacts/', 'company' => '/companies/', 'lead' => '/leads/'][$type] . $id;
            LiteAssert::same($expected, $location, "redirect for {$type}");

            $notes = (new NoteRepository())->forSubject($type, $id);
            LiteAssert::same(1, count($notes), "note stored for {$type}");

            $controller()->deleteNote($post($type, $id, [], ['noteId' => (string) $notes[0]['id']]));
            LiteAssert::same(0, count((new NoteRepository())->forSubject($type, $id)), "note deleted for {$type}");
        }
    },

    'tags: attach and remove on contact/company/lead' => function () use ($setup, $ctx, $controller, $post, $subjects) {
        $setup();
        foreach ($subjects() as $type => $id) {
            $controller()->addTag($post($type, $id, ['name' => 'lite-tag']));
            $tags = (new TagRepository())->forSubject($type, $id);
            LiteAssert::same(['lite-tag'], array_column($tags, 'name'), "tag on {$type}");

            $controller()->removeTag($post($type, $id, [], ['tagId' => (string) $tags[0]['id']]));
            LiteAssert::same([], (new TagRepository())->forSubject($type, $id), "tag removed from {$type}");
        }
    },

    'timeline records note/tag events per subject' => function () use ($setup, $ctx, $controller, $post) {
        $setup();
        $controller()->addTag($post('company', $ctx->companyId, ['name' => 'lite-timeline']));
        $events = array_column((new TimelineEventRepository())->forSubject('company', $ctx->companyId), 'type');
        LiteAssert::true(in_array('tag_added', $events, true), 'tag_added on the timeline');
    },

    'attachments: upload without a file is refused, download of unknown id 404s' => function () use ($setup, $ctx, $controller, $post) {
        $setup();
        foreach (['contact' => $ctx->contactId, 'company' => $ctx->companyId, 'lead' => $ctx->leadId] as $type => $id) {
            $controller()->uploadAttachment($post($type, $id));
            LiteAssert::same('Choose a file first.', $_SESSION['_flash']['error'] ?? null, "upload guard for {$type}");
            unset($_SESSION['_flash']);
            LiteAssert::throws(NotFoundException::class, fn () => $controller()->downloadAttachment($post($type, $id, [], ['attachmentId' => '999999999'])));
        }
    },

    'unknown or unregistered record types still 404' => function () use ($setup, $ctx, $controller, $post) {
        $setup();
        foreach (['deal', 'contract', 'project', 'widget', ''] as $type) {
            LiteAssert::throws(NotFoundException::class, fn () => $controller()->addNote($post($type, 1, ['body' => 'x'])), "type [{$type}]");
        }
    },

    'a user without contacts.view cannot attach notes to contacts' => function () use ($setup, $ctx, $controller, $post) {
        $setup();
        $types = $ctx->c->make(RecordTypeRegistry::class);
        $noPerms = ['id' => -1, 'organization_id' => $ctx->user['organization_id'], 'role_id' => null, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null];
        LiteAssert::false($types->canView('contact', $noPerms));

        $original = CurrentUser::user();
        CurrentUser::set($noPerms);
        try {
            LiteAssert::throws(NotFoundException::class, fn () => $controller()->addNote($post('contact', $ctx->contactId, ['body' => 'x'])));
        } finally {
            CurrentUser::set($original);
        }
    },

    'rollback leaves no test data behind' => function () use ($setup, $ctx) {
        $setup();
        $pdo = Connection::pdo();
        $pdo->rollBack();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM contacts WHERE first_name = 'LiteTest'")->fetchColumn();
        LiteAssert::same(0, $count, 'test contact removed by rollback');
    },
];
