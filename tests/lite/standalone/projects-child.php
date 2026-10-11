<?php

declare(strict_types=1);

/**
 * Runs INSIDE a temporary copy of the app where modules/ contains only
 * Projects (see tests/lite/StandaloneProjectsTest.php). Prints one JSON
 * object: {"checks": [[name, ok, detail], ...]}. crm_local only, one
 * transaction, always rolled back.
 */

$root = $argv[1];
require $root . '/app/autoload.php';

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;
use Modules\Projects\Services\ProjectService;

$checks = [];
$check = function (string $name, callable $fn) use (&$checks): void {
    try {
        $result = $fn();
        $checks[] = [$name, $result === true, $result === true ? '' : (is_string($result) ? $result : json_encode($result))];
    } catch (\Throwable $e) {
        $checks[] = [$name, false, get_class($e) . ': ' . $e->getMessage()];
    }
};

$app = new Application($root);
$app->loadRoutes();
restore_error_handler();
restore_exception_handler();
$pdo = Connection::pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'crm_local') {
    echo json_encode(['checks' => [['refuse: not crm_local', false, '']]]);
    exit(1);
}

$adminId = (int) $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.`key` = 'org_admin' AND u.organization_id IS NOT NULL AND u.status = 'active' ORDER BY u.id LIMIT 1")->fetchColumn();
$admin = (new UserRepository())->findById($adminId);
$orgId = (int) $admin['organization_id'];
CurrentUser::set($admin);
OrganizationContext::set($orgId);

$read = fn (Response $r, string $p) => (new \ReflectionProperty(Response::class, $p))->getValue($r);
$req = function (string $method, string $path, array $query = [], array $body = []) use ($app, $read): array {
    $app->container->make(ModuleGate::class)->reset();
    $_SERVER['REQUEST_URI'] = $path;
    if ($method === 'POST') {
        $body['_csrf'] = Csrf::token();
    }
    $r = $app->router->dispatch(new Request($method, $path, $query, $body, [], []));

    return [$read($r, 'status'), (string) $read($r, 'body'), $read($r, 'headers')['Location'] ?? null];
};
$notFound = function (callable $fn): bool|string {
    try {
        $fn();

        return 'no exception';
    } catch (\App\Core\Exceptions\NotFoundException) {
        return true;
    }
};

$pdo->beginTransaction();
try {
    // A real Core + Projects install has no rows for modules whose code was
    // never installed (Sync only adds what is on disk). crm_local has them
    // from the full install, so drop them here (rolled back).
    $pdo->exec("DELETE FROM modules WHERE `key` <> 'projects'");
    $pdo->exec("DELETE FROM modules WHERE `key` = 'projects'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('projects', 'Projects', '0.1.0', 'addon', 0, 'active', '[]')");
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$orgId, (int) $pdo->lastInsertId()]);

    $check('only Projects is installed on disk', fn () => array_map(fn ($m) => $m->key, ModuleRegistry::instance()->all()) === ['projects'] ?: json_encode(array_map(fn ($m) => $m->key, ModuleRegistry::instance()->all())));
    $check('Sales and Contracts classes are really absent', fn () => !class_exists('Modules\\Sales\\SalesModule') && !class_exists('Modules\\Contracts\\ContractsModule') && !class_exists('Modules\\Sales\\Events\\DealStageChanged'));
    $check('record types: project registered, deal and contract not', function () use ($app) {
        $types = $app->container->make(RecordTypeRegistry::class);

        return $types->has('project') && !$types->has('deal') && !$types->has('contract');
    });
    $check('project list renders (200), sidebar has Projects, no Deals/Contracts link', function () use ($req) {
        [$s, $b] = $req('GET', '/projects');

        return $s === 200 && str_contains($b, 'href="/projects"') && !str_contains($b, 'href="/deals') && !str_contains($b, 'href="/contracts') ?: "status {$s}";
    });

    $contact = (string) $pdo->query("SELECT id FROM contacts WHERE organization_id = {$orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $id = 0;
    $check('create through the real route', function () use ($req, $contact, &$id) {
        [$s, , $loc] = $req('POST', '/projects', [], ['name' => 'LiteTest standalone project', 'contact_id' => $contact, 'start_date' => '2026-01-01', 'expected_end_date' => '2026-12-31']);
        $id = (int) preg_replace('#^/projects/(\d+)$#', '$1', (string) $loc);

        return $s === 302 && $id > 0 ?: "status {$s} location {$loc}";
    });
    $check('full lifecycle: plan → start → hold → resume → complete → reopen', function () use ($req, $id, $pdo) {
        $seen = [];
        foreach (['plan', 'start', 'hold', 'resume', 'complete', 'reopen'] as $action) {
            $req('POST', "/projects/{$id}/status", [], ['action' => $action]);
            $seen[] = $pdo->query("SELECT status FROM projects WHERE id = {$id}")->fetchColumn();
        }
        $code = $pdo->query("SELECT code FROM projects WHERE id = {$id}")->fetchColumn();

        return $seen === ['planned', 'active', 'on_hold', 'active', 'completed', 'active'] && preg_match('/^PRJ-\d{4,}$/', (string) $code) === 1 ?: json_encode([$seen, $code]);
    });
    $check('members: add and list', function () use ($req, $id, $pdo, $orgId, $adminId) {
        $other = $pdo->query("SELECT id FROM users WHERE organization_id = {$orgId} AND id <> {$adminId} AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        if ($other === false) {
            return true;
        }
        $req('POST', "/projects/{$id}/members", [], ['user_id' => (string) $other]);

        return (int) $pdo->query("SELECT COUNT(*) FROM project_members WHERE project_id = {$id}")->fetchColumn() === 1;
    });
    $check('notes on a project (Core /records route)', function () use ($req, $id, $pdo) {
        [, , $loc] = $req('POST', "/records/project/{$id}/notes", [], ['body' => 'standalone note']);

        return $loc === "/projects/{$id}" && (int) $pdo->query("SELECT COUNT(*) FROM notes WHERE notable_type = 'project' AND notable_id = {$id}")->fetchColumn() === 1;
    });
    $check('project page renders with no deal or contract UI', function () use ($req, $id) {
        [$s, $b] = $req('GET', "/projects/{$id}");

        return $s === 200 && !str_contains($b, '/deals/') && !str_contains($b, '/contracts/') && !str_contains($b, 'from_deal') ?: "status {$s}";
    });
    $check('contact page shows the Projects panel', function () use ($req, $contact) {
        [$s, $b] = $req('GET', "/contacts/{$contact}");

        return $s === 200 && str_contains($b, 'data-related-panel="projects.contact"') && str_contains($b, 'LiteTest standalone project') ?: "status {$s}";
    });
    $check('deal/contract features degrade: ?from_deal= and ?from_contract= are 404, their routes do not exist', function () use ($req, $notFound) {
        return $notFound(fn () => $req('GET', '/projects/create', ['from_deal' => '1'])) === true
            && $notFound(fn () => $req('GET', '/projects/create', ['from_contract' => '1'])) === true
            && $notFound(fn () => $req('GET', '/deals/1')) === true
            && $notFound(fn () => $req('GET', '/contracts/1')) === true;
    });
    $check('service: linking a deal or a contract is refused', function () use ($app, $admin, $id) {
        $svc = $app->container->make(ProjectService::class);
        foreach (['deal', 'contract'] as $type) {
            try {
                $svc->link($admin, $id, $type, 1);

                return "{$type} link accepted";
            } catch (\App\Core\Exceptions\ValidationException) {
            }
        }

        return $svc->draftFrom($admin, 'deal', 1) === null && $svc->draftFrom($admin, 'contract', 1) === null;
    });
    $check('links created while Sales/Contracts existed show as plain text', function () use ($app, $admin, $id, $pdo, $orgId, $req) {
        $pdo->prepare('INSERT INTO project_links (organization_id, project_id, linked_type, linked_id, linked_label) VALUES (?, ?, ?, ?, ?)')->execute([$orgId, $id, 'deal', 1, 'Old deal']);
        $pdo->prepare('INSERT INTO project_links (organization_id, project_id, linked_type, linked_id, linked_label) VALUES (?, ?, ?, ?, ?)')->execute([$orgId, $id, 'contract', 1, 'Old contract']);
        $links = $app->container->make(ProjectService::class)->links($admin, $id);
        [$s, $b] = $req('GET', "/projects/{$id}");

        return $links[0]['url'] === null && $links[1]['url'] === null && $s === 200 && str_contains($b, 'Old deal') && str_contains($b, 'Old contract') ?: json_encode($links);
    });
    $check('settings page works', function () use ($req) {
        [$s] = $req('GET', '/projects/settings');

        return $s === 200 ?: "status {$s}";
    });
    $check('Core pages still work without any other optional module (contacts list, companies, leads)', function () use ($req) {
        foreach (['/contacts', '/companies', '/leads'] as $p) {
            [$s] = $req('GET', $p);
            if ($s !== 200) {
                return "{$p} → {$s}";
            }
        }

        return true;
    });
} finally {
    $pdo->rollBack();
}

echo json_encode(['checks' => $checks], JSON_UNESCAPED_UNICODE);
