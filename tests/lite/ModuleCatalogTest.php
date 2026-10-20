<?php

declare(strict_types=1);

/**
 * Modules & Features catalog + module activation requests, end to end
 * through the real container, router and database (crm_local only, one
 * transaction, rolled back).
 *
 * Tests run in order and share fixtures.
 */

use App\Application\Services\ModuleActivationRequestService;
use App\Application\Services\ModuleCatalog;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\Session;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';

$h = new \stdClass();
$h->ready = false;
$h->reason = '';

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT pending_key FROM module_activation_requests LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or migration 0088 not applied: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $superId = $a->pdo->query('SELECT id FROM users WHERE is_super_admin = 1 AND status = \'active\' ORDER BY id LIMIT 1')->fetchColumn();
    if ($superId === false || $a->otherOrgId === null) {
        $h->reason = 'needs an active Super Admin and a second organization';
        throw new LiteSkip($h->reason);
    }
    $h->super = (new UserRepository())->findById((int) $superId);
    $h->svc = $a->container()->make(ModuleActivationRequestService::class);
    $h->moduleId = fn (string $key): int => (int) $a->pdo->query('SELECT id FROM modules WHERE `key` = ' . $a->pdo->quote($key))->fetchColumn();
    // Every module registered for both organizations (some local seeds only carry rows for org 1/2).
    foreach ([$a->orgId, $a->otherOrgId] as $org) {
        $a->pdo->exec("INSERT IGNORE INTO organization_modules (organization_id, module_id, enabled) SELECT {$org}, id, 1 FROM modules");
    }
    $h->entitle = function (string $key, bool $on, ?int $org = null) use ($a): void {
        $a->pdo->prepare('UPDATE organization_modules om JOIN modules m ON m.id = om.module_id SET om.enabled = ? WHERE om.organization_id = ? AND m.`key` = ?')
            ->execute([$on ? 1 : 0, $org ?? $a->orgId, $key]);
        $a->container()->make(OrganizationEntitlements::class)->reset();
    };
    $h->card = function (string $key) use ($h): ?array {
        foreach ($h->svc->catalog($h->a->admin) as $modules) {
            foreach ($modules as $m) {
                if ($m['key'] === $key) {
                    return $m;
                }
            }
        }

        return null;
    };
    $h->pending = fn (string $key, ?int $org = null): ?array => $a->pdo->query(
        "SELECT * FROM module_activation_requests WHERE status = 'pending' AND module_key = " . $a->pdo->quote($key) . ' AND organization_id = ' . ($org ?? $a->orgId)
    )->fetch(\PDO::FETCH_ASSOC) ?: null;
    $h->errorOf = function (callable $fn): string {
        try {
            $fn();
        } catch (ValidationException $e) {
            return (string) (array_values($e->errors)[0][0] ?? '');
        }

        return '';
    };
    $a->pdo->exec('DELETE FROM module_activation_requests');
    $h->ready = true;
};

return [
    'catalog page renders for an org admin, grouped by category, with no prices' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        ($h->entitle)('woocommerce', false);
        $page = $a->request('GET', '/org/modules/catalog');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('Modules &amp; Features', $page['body']);
        foreach (['Sales &amp; customers', 'Online shop', 'Marketing &amp; customer experience', 'Finance'] as $category) {
            LiteAssert::contains($category, $page['body'], "category {$category}");
        }
        LiteAssert::contains('id="module-woocommerce"', $page['body']);
        LiteAssert::notContains('id="module-demo"', $page['body'], 'sample module hidden');
        foreach (['price', 'Price', 'تومان', 'ریال', '/checkout', '/pay'] as $word) {
            LiteAssert::notContains($word, $page['body'], "no {$word} on the catalog");
        }
    },

    'catalog requires organization.edit' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->noRole);
        LiteAssert::same('forbidden', $a->outcome('GET', '/org/modules/catalog'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/org/modules/catalog/woocommerce/request'));
        LiteAssert::throws(AuthorizationException::class, fn () => $h->svc->catalog($a->noRole));
        if ($a->member !== null) {
            $a->as($a->member);
            LiteAssert::same('forbidden', $a->outcome('GET', '/org/modules/catalog'));
        }
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
    },

    'WooCommerce card: verified highlights, real status, request button when not entitled' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        ($h->entitle)('woocommerce', false);
        $card = ($h->card)('woocommerce');
        LiteAssert::same('available', $card['state']);
        LiteAssert::same('ecommerce', ModuleCatalog::categoryOf('woocommerce'));
        LiteAssert::same(null, $card['open_url'], 'no link into the integration while not entitled');
        LiteAssert::same(6, count($card['highlights']));
        $body = $a->request('GET', '/org/modules/catalog')['body'];
        LiteAssert::contains('Connect one or more WooCommerce shops', $body);
        LiteAssert::contains('/org/modules/catalog/woocommerce/request', $body);
        LiteAssert::notContains('/org/integrations#provider-woocommerce', $body);

        ($h->entitle)('woocommerce', true);
        $card = ($h->card)('woocommerce');
        LiteAssert::same('active', $card['state']);
        LiteAssert::same('/org/integrations#provider-woocommerce', $card['open_url']);
        $body = $a->request('GET', '/org/modules/catalog')['body'];
        LiteAssert::contains('/org/integrations#provider-woocommerce', $body);
        LiteAssert::notContains('/org/modules/catalog/woocommerce/request', $body, 'no request button when active');
    },

    'WooCommerce integration routes unchanged: open when entitled, protected when not' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        ($h->entitle)('woocommerce', true);
        $a->pdo->exec("INSERT INTO integration_connections (organization_id, provider, name, enabled, status, webhook_key, created_at, updated_at)
            VALUES ({$a->orgId}, 'woocommerce', 'Catalog test shop', 1, 'active', '" . str_repeat('c', 40) . "', NOW(), NOW())");
        $connection = (int) $a->pdo->lastInsertId();
        $page = $a->request('GET', '/org/integrations');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('provider-card" id="provider-woocommerce"', $page['body'], 'card usable when entitled');
        LiteAssert::same('ok', $a->outcome('GET', '/org/integrations/' . $connection . '/contact-sync'));

        ($h->entitle)('woocommerce', false);
        LiteAssert::contains('provider-card--unavailable" id="provider-woocommerce"', $a->request('GET', '/org/integrations')['body'], 'card locked when not entitled');
        LiteAssert::same('notfound', $a->outcome('GET', '/org/integrations/' . $connection . '/contact-sync'), 'module gate still blocks');
        $a->as($a->noRole);
        LiteAssert::same('forbidden', $a->outcome('GET', '/org/integrations'));
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        ($h->entitle)('woocommerce', true);
    },

    'dependencies are shown and add-ons sit under their parent' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->entitle)('wallet', false);
        ($h->entitle)('notifications', false);
        $categories = $h->svc->catalog($a->admin);
        $keys = array_column($categories['marketing_cx'], 'key');
        LiteAssert::same('marketing', $keys[0]);
        LiteAssert::true(in_array('marketing_email', $keys, true) && in_array('bale_messaging', $keys, true));
        foreach ($categories['marketing_cx'] as $m) {
            if (in_array($m['key'], ['marketing_email', 'bale_messaging'], true)) {
                LiteAssert::true($m['child'], $m['key'] . ' is listed as an add-on of marketing');
                LiteAssert::same('marketing', $m['dependencies'][0]['key']);
            }
        }
        $marketing = ($h->card)('marketing');
        LiteAssert::same(['marketing_email', 'bale_messaging'], array_values(array_intersect(['marketing_email', 'bale_messaging'], $marketing['dependents'])));
        $notifications = ($h->card)('notifications');
        LiteAssert::same('wallet', $notifications['dependencies'][0]['key']);
        LiteAssert::false($notifications['dependencies'][0]['active'], 'wallet shown as not active');
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        LiteAssert::contains('Requires Wallet', $a->request('GET', '/org/modules/catalog')['body']);
    },

    'request activation creates one pending request (duplicates refused)' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        ($h->entitle)('woocommerce', false);
        $response = $a->request('POST', '/org/modules/catalog/woocommerce/request');
        LiteAssert::same('/org/modules/catalog#module-woocommerce', $response['location']);
        LiteAssert::same('Your activation request was sent. You will be notified when it is reviewed.', Session::getFlash('success'));
        $pending = ($h->pending)('woocommerce');
        LiteAssert::true($pending !== null, 'pending request stored');
        LiteAssert::same((int) $a->admin['id'], (int) $pending['requested_by_user_id']);
        LiteAssert::same('pending', ($h->card)('woocommerce')['state']);
        LiteAssert::false(($h->card)('woocommerce')['state'] === 'active', 'nothing activated automatically');
        LiteAssert::same(0, (int) $a->pdo->query("SELECT enabled FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = " . ($h->moduleId)('woocommerce'))->fetchColumn());

        $a->request('POST', '/org/modules/catalog/woocommerce/request');
        LiteAssert::same('An activation request for this module is already pending.', Session::getFlash('error'));
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM module_activation_requests WHERE organization_id = {$a->orgId} AND module_key = 'woocommerce'")->fetchColumn());
        LiteAssert::same('catalog.error.already_pending', ($h->errorOf)(fn () => $h->svc->request($a->admin, 'woocommerce')));
        $body = $a->request('GET', '/org/modules/catalog')['body'];
        LiteAssert::contains('Request pending', $body);
        LiteAssert::notContains('/org/modules/catalog/woocommerce/request', $body);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'organization' AND subject_id = {$a->orgId} AND type = 'module_request.created'")->fetchColumn());
    },

    'unknown, sample, platform-disabled and already-active modules cannot be requested' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        LiteAssert::same('catalog.error.unknown_module', ($h->errorOf)(fn () => $h->svc->request($a->admin, 'no_such_module')));
        LiteAssert::same('catalog.error.unknown_module', ($h->errorOf)(fn () => $h->svc->request($a->admin, 'demo')));
        $a->setModuleStatus('projects', 'disabled');
        ($h->entitle)('projects', false);
        LiteAssert::same('catalog.error.unknown_module', ($h->errorOf)(fn () => $h->svc->request($a->admin, 'projects')));
        LiteAssert::same(null, ($h->card)('projects'), 'platform-disabled module not offered');
        $a->setModuleStatus('projects', 'active');
        ($h->entitle)('projects', true);
        LiteAssert::same('catalog.error.already_active', ($h->errorOf)(fn () => $h->svc->request($a->admin, 'projects')));
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        $a->request('POST', '/org/modules/catalog/no_such_module/request');
        LiteAssert::same('This module is not available for activation requests.', Session::getFlash('error'));
    },

    'organization isolation: requests and states never cross organizations' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->entitle)('woocommerce', false, $a->otherOrgId);
        LiteAssert::same(null, ($h->pending)('woocommerce', $a->otherOrgId), 'the other organization has no request');
        $otherAdmin = array_merge($a->admin, ['id' => (int) $a->admin['id'], 'organization_id' => $a->otherOrgId]);
        $card = null;
        foreach ($h->svc->catalog($otherAdmin) as $modules) {
            foreach ($modules as $m) {
                $card = $m['key'] === 'woocommerce' ? $m : $card;
            }
        }
        LiteAssert::same('available', $card['state'], 'our pending request is not shown to the other organization');
        ($h->entitle)('woocommerce', true, $a->otherOrgId);
    },

    'only Super Admin can review' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $id = (int) ($h->pending)('woocommerce')['id'];
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        LiteAssert::same('forbidden', $a->outcome('GET', '/admin/module-requests'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/admin/module-requests/' . $id . '/approve'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/admin/module-requests/' . $id . '/reject'));
        LiteAssert::throws(AuthorizationException::class, fn () => $h->svc->approve($a->admin, $id));
        LiteAssert::same('pending', ($h->pending)('woocommerce')['status']);

        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $page = $a->request('GET', '/admin/module-requests');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('/admin/module-requests/' . $id . '/approve', $page['body']);
        $orgPage = $a->request('GET', '/admin/organizations/' . $a->orgId . '/modules');
        LiteAssert::contains('/admin/module-requests/' . $id . '/reject', $orgPage['body'], 'pending request shown on the organization module screen');
        LiteAssert::contains('name="back_organization"', $orgPage['body']);
    },

    'pending requests are listed first' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->pdo->exec("INSERT INTO module_activation_requests (organization_id, module_id, module_key, status, requested_by_user_id, created_at, updated_at)
            VALUES ({$a->otherOrgId}, " . ($h->moduleId)('finance') . ", 'finance', 'rejected', NULL, NOW(), NOW())");
        $rows = $h->svc->adminPage($h->super, [], 50, 0);
        LiteAssert::same('pending', $rows[0]['status']);
        LiteAssert::same('rejected', $rows[count($rows) - 1]['status']);
        LiteAssert::same(1, $h->svc->adminCount($h->super, ['status' => 'pending']));
    },

    'approve fails safely when a dependency is missing (request stays pending)' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->entitle)('wallet', false);
        ($h->entitle)('notifications', false);
        $h->svc->request($a->admin, 'notifications');
        $id = (int) ($h->pending)('notifications')['id'];
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $response = $a->request('POST', '/admin/module-requests/' . $id . '/approve');
        LiteAssert::same('/admin/module-requests', $response['location']);
        LiteAssert::contains('still pending', (string) Session::getFlash('error'));
        LiteAssert::same('pending', ($h->pending)('notifications')['status'] ?? null, 'request still pending');
        LiteAssert::same(0, (int) $a->pdo->query("SELECT enabled FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = " . ($h->moduleId)('notifications'))->fetchColumn());
    },

    'approve enables the module via the existing entitlement, audits and notifies' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $id = (int) ($h->pending)('woocommerce')['id'];
        $announcementsBefore = (int) $a->pdo->query("SELECT COUNT(*) FROM admin_announcement_recipients WHERE user_id = {$a->admin['id']}")->fetchColumn();
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $response = $a->request('POST', '/admin/module-requests/' . $id . '/approve', [], ['back_organization' => (string) $a->orgId]);
        LiteAssert::same('/admin/organizations/' . $a->orgId . '/modules', $response['location']);
        LiteAssert::same('Request approved; the module is now active for the organization.', Session::getFlash('success'));
        $row = $a->pdo->query("SELECT * FROM module_activation_requests WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same('approved', $row['status']);
        LiteAssert::same((int) $h->super['id'], (int) $row['reviewed_by_user_id']);
        LiteAssert::same(null, $row['pending_key']);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT enabled FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = " . ($h->moduleId)('woocommerce'))->fetchColumn());
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'organization' AND subject_id = {$a->orgId} AND type = 'module_request.approved'")->fetchColumn());
        LiteAssert::same($announcementsBefore + 1, (int) $a->pdo->query("SELECT COUNT(*) FROM admin_announcement_recipients WHERE user_id = {$a->admin['id']}")->fetchColumn());
        $a->container()->make(OrganizationEntitlements::class)->reset();
        LiteAssert::same('active', ($h->card)('woocommerce')['state']);
    },

    'a second review of the same request changes nothing' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $id = (int) $a->pdo->query("SELECT id FROM module_activation_requests WHERE organization_id = {$a->orgId} AND module_key = 'woocommerce' ORDER BY id DESC LIMIT 1")->fetchColumn();
        LiteAssert::same('catalog.admin.error.already_processed', ($h->errorOf)(fn () => $h->svc->reject($h->super, $id, 'late')));
        LiteAssert::same('catalog.admin.error.already_processed', ($h->errorOf)(fn () => $h->svc->approve($h->super, $id)));
        LiteAssert::same('approved', $a->pdo->query("SELECT status FROM module_activation_requests WHERE id = {$id}")->fetchColumn());
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $a->request('POST', '/admin/module-requests/' . $id . '/reject', [], ['reason' => 'late']);
        LiteAssert::same('This request has already been reviewed.', Session::getFlash('error'));
    },

    'approving when the module was already granted reports it' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->entitle)('knowledge', false);
        $h->svc->request($a->admin, 'knowledge');
        ($h->entitle)('knowledge', true); // granted meanwhile from the organization module screen
        LiteAssert::true($h->svc->approve($h->super, (int) $a->pdo->query("SELECT id FROM module_activation_requests WHERE module_key = 'knowledge' AND organization_id = {$a->orgId}")->fetchColumn()));
    },

    'reject keeps history with the reason, notifies, and allows a new request' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $id = (int) ($h->pending)('notifications')['id'];
        LiteAssert::same('catalog.admin.error.reason_length', ($h->errorOf)(fn () => $h->svc->reject($h->super, $id, str_repeat('x', 501))));
        $announcementsBefore = (int) $a->pdo->query("SELECT COUNT(*) FROM admin_announcement_recipients WHERE user_id = {$a->admin['id']}")->fetchColumn();
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $a->request('POST', '/admin/module-requests/' . $id . '/reject', [], ['reason' => 'Enable Wallet first']);
        LiteAssert::same('Request rejected.', Session::getFlash('success'));
        $row = $a->pdo->query("SELECT * FROM module_activation_requests WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same('rejected', $row['status']);
        LiteAssert::same('Enable Wallet first', $row['rejection_reason']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT enabled FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = " . ($h->moduleId)('notifications'))->fetchColumn(), 'module stays off');
        LiteAssert::same($announcementsBefore + 1, (int) $a->pdo->query("SELECT COUNT(*) FROM admin_announcement_recipients WHERE user_id = {$a->admin['id']}")->fetchColumn());
        $body = (string) $a->pdo->query("SELECT a.body FROM admin_announcements a JOIN admin_announcement_recipients r ON r.announcement_id = a.id WHERE r.user_id = {$a->admin['id']} ORDER BY a.id DESC LIMIT 1")->fetchColumn();
        LiteAssert::contains('Enable Wallet first', $body);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'organization' AND subject_id = {$a->orgId} AND type = 'module_request.rejected'")->fetchColumn());

        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        LiteAssert::same('rejected', ($h->card)('notifications')['state']);
        $page = $a->request('GET', '/org/modules/catalog')['body'];
        LiteAssert::contains('Request rejected', $page);
        LiteAssert::contains('Reason: Enable Wallet first', $page);
        LiteAssert::contains('Request again', $page);
        $h->svc->request($a->admin, 'notifications');
        LiteAssert::same('pending', ($h->card)('notifications')['state']);
        LiteAssert::same(2, (int) $a->pdo->query("SELECT COUNT(*) FROM module_activation_requests WHERE organization_id = {$a->orgId} AND module_key = 'notifications'")->fetchColumn(), 'history kept');
    },

    'existing Super Admin grant/revoke on the organization module screen still works' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $id = ($h->moduleId)('contracts');
        LiteAssert::same('ok', $a->outcome('POST', '/admin/organizations/' . $a->orgId . '/modules/' . $id . '/disable'));
        LiteAssert::same(0, (int) $a->pdo->query("SELECT enabled FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$id}")->fetchColumn());
        LiteAssert::same('ok', $a->outcome('POST', '/admin/organizations/' . $a->orgId . '/modules/' . $id . '/enable'));
        LiteAssert::same(1, (int) $a->pdo->query("SELECT enabled FROM organization_modules WHERE organization_id = {$a->orgId} AND module_id = {$id}")->fetchColumn());
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        LiteAssert::same('forbidden', $a->outcome('POST', '/admin/organizations/' . $a->orgId . '/modules/' . $id . '/disable'));
    },

    'catalog keys are translated in all five languages' => function () use ($setup, $h) {
        $root = dirname(__DIR__, 2) . '/lang/';
        $en = require $root . 'en.php';
        $keys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'catalog.'));
        LiteAssert::true(count($keys) > 90);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $other = require $root . $lang . '.php';
            LiteAssert::same([], array_values(array_diff($keys, array_keys($other))), "{$lang} has every catalog key");
        }
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM module_activation_requests")->fetchColumn());
    },
];
