<?php

declare(strict_types=1);

/**
 * Runs INSIDE a temporary copy of the app where modules/ contains only
 * Contracts (see tests/lite/StandaloneContractsTest.php). Prints one
 * JSON object: {"checks": [[name, ok, detail], ...]}. crm_local only,
 * one transaction, always rolled back.
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
use Modules\Contracts\Services\ContractService;

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
    // A real Core + Contracts install has no rows for modules whose code was
    // never installed (Sync only adds what is on disk). crm_local has them
    // from the full install, so drop them here (rolled back, cascades to
    // organization_modules / user_module_overrides).
    $pdo->exec("DELETE FROM modules WHERE `key` <> 'contracts'");
    $pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$orgId, (int) $pdo->lastInsertId()]);

    $check('only Contracts is installed on disk', fn () => array_map(fn ($m) => $m->key, ModuleRegistry::instance()->all()) === ['contracts'] ?: json_encode(array_map(fn ($m) => $m->key, ModuleRegistry::instance()->all())));
    $check('Sales classes are really absent', fn () => !class_exists('Modules\\Sales\\SalesModule') && !class_exists('Modules\\Sales\\Events\\DealStageChanged'));
    $check('record types: contract registered, deal not', fn () => $app->container->make(RecordTypeRegistry::class)->has('contract') && !$app->container->make(RecordTypeRegistry::class)->has('deal'));
    $check('contract list renders (200), sidebar has Contracts, no Deals link', function () use ($req) {
        [$s, $b] = $req('GET', '/contracts');

        return $s === 200 && str_contains($b, 'href="/contracts"') && !str_contains($b, 'href="/deals') ?: "status {$s}";
    });

    $currency = (string) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $contact = (string) $pdo->query("SELECT id FROM contacts WHERE organization_id = {$orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $id = 0;
    $check('create through the real route', function () use ($req, $currency, $contact, &$id) {
        [$s, , $loc] = $req('POST', '/contracts', [], ['title' => 'LiteTest standalone', 'contact_id' => $contact, 'start_date' => '2026-01-01', 'end_date' => '2099-12-31', 'value' => '500', 'currency_id' => $currency]);
        $id = (int) preg_replace('#^/contracts/(\d+)$#', '$1', (string) $loc);

        return $s === 302 && $id > 0 ?: "status {$s} location {$loc}";
    });
    $check('full lifecycle: submit → approve (numbered) → renew → terminate', function () use ($req, $id, $pdo) {
        $req('POST', "/contracts/{$id}/submit");
        $req('POST', "/contracts/{$id}/approve", [], ['signed_on' => '2026-01-01']);
        $row = $pdo->query("SELECT status, contract_number FROM contracts WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        [, , $renewLoc] = $req('POST', "/contracts/{$id}/renew");
        $req('POST', "/contracts/{$id}/terminate", [], ['reason' => 'test']);
        $after = $pdo->query("SELECT status FROM contracts WHERE id = {$id}")->fetchColumn();

        return $row['status'] === 'active' && preg_match('/^CTR-\d{4,}$/', (string) $row['contract_number']) === 1 && str_starts_with((string) $renewLoc, '/contracts/') && $after === 'terminated' ?: json_encode([$row, $renewLoc, $after]);
    });
    $check('notes on a contract (Core /records route)', function () use ($req, $id, $pdo) {
        [, , $loc] = $req('POST', "/records/contract/{$id}/notes", [], ['body' => 'standalone note']);

        return $loc === "/contracts/{$id}" && (int) $pdo->query("SELECT COUNT(*) FROM notes WHERE notable_type = 'contract' AND notable_id = {$id}")->fetchColumn() === 1;
    });
    $check('contract page renders with no deal UI', function () use ($req, $id) {
        [$s, $b] = $req('GET', "/contracts/{$id}");

        return $s === 200 && !str_contains($b, '/deals/') && !str_contains($b, 'from_deal') ?: "status {$s}";
    });
    $check('contact page shows the Contracts panel', function () use ($req, $contact) {
        [$s, $b] = $req('GET', "/contacts/{$contact}");

        return $s === 200 && str_contains($b, 'data-related-panel="contracts.contact"') && str_contains($b, 'LiteTest standalone') ?: "status {$s}";
    });
    $check('deal features degrade: ?from_deal= is 404, deal routes do not exist', function () use ($req, $notFound) {
        return $notFound(fn () => $req('GET', '/contracts/create', ['from_deal' => '1'])) === true
            && $notFound(fn () => $req('GET', '/deals/1')) === true;
    });
    $check('service: no deal lookup, linking a deal refused', function () use ($app, $admin, $id) {
        $svc = $app->container->make(ContractService::class);
        try {
            $svc->link($admin, $id, 'deal', 1);

            return 'link accepted';
        } catch (\App\Core\Exceptions\ValidationException) {
            return $svc->draftFromDeal($admin, 1) === null;
        }
    });
    $check('a link created while Sales existed shows as plain text', function () use ($app, $admin, $id, $pdo, $orgId, $req) {
        $pdo->prepare('INSERT INTO contract_links (organization_id, contract_id, linked_type, linked_id, linked_label) VALUES (?, ?, ?, ?, ?)')->execute([$orgId, $id, 'deal', 1, 'Old deal']);
        $links = $app->container->make(ContractService::class)->links($admin, $id);
        [$s, $b] = $req('GET', "/contracts/{$id}");

        return $links[0]['url'] === null && $links[0]['label'] === 'Old deal' && $s === 200 && str_contains($b, 'Old deal') ?: json_encode($links);
    });
    $check('settings + categories pages work', function () use ($req) {
        [$a] = $req('GET', '/contracts/settings');
        [$b] = $req('GET', '/contracts/categories');

        return $a === 200 && $b === 200 ?: "{$a}/{$b}";
    });
    $check('Core pages still work without any optional module (contacts list, companies, leads)', function () use ($req) {
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
