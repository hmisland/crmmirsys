<?php

declare(strict_types=1);

/**
 * Step 2.4 — ContractService + ContractRepository + Core number series.
 *
 * Runs against crm_local only, inside one transaction rolled back at the
 * end. Uses real users of one organization: the org admin, and another
 * active user of the same org acting with the system `member` role.
 */

use App\Core\Application;
use App\Core\Events\EventBus;
use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Repositories\NumberSeriesRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TimelineEventRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use Modules\Contracts\Events\ContractActivated;
use Modules\Contracts\Events\ContractTerminated;
use Modules\Contracts\Services\ContractService;

$db = new \stdClass();
$db->ready = false;
$db->reason = '';

$setup = function () use ($db): void {
    if ($db->ready) {
        return;
    }
    if ($db->reason !== '') {
        throw new LiteSkip($db->reason);
    }

    try {
        $app = new Application(dirname(__DIR__, 2));
        $app->loadRoutes();
        restore_error_handler();
        restore_exception_handler();

        $pdo = Connection::pdo();
        if (Config::get('app.env') !== 'local' || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'crm_local') {
            throw new \RuntimeException('not the local crm_local database');
        }
        foreach (['contracts', 'number_series'] as $table) {
            if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchColumn() !== 1) {
                throw new \RuntimeException("table {$table} missing in crm_local");
            }
        }
        $adminId = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.`key` = 'org_admin' AND u.organization_id IS NOT NULL AND u.status = 'active' ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        if ($adminId === false) {
            throw new \RuntimeException('no active org_admin user');
        }
    } catch (\Throwable $e) {
        $db->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($db->reason);
    }

    $users = new UserRepository();
    $admin = $users->findById((int) $adminId);
    $orgId = (int) $admin['organization_id'];
    $memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();
    $otherUserId = $pdo->query("SELECT id FROM users WHERE organization_id = {$orgId} AND id <> {$adminId} AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($otherUserId === false) {
        $db->reason = 'needs a second active user in the organization';
        throw new LiteSkip($db->reason);
    }
    // A real user of the org, acting with the system member role.
    $member = array_merge($users->findById((int) $otherUserId), ['role_id' => $memberRoleId, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null]);

    CurrentUser::set($admin);
    OrganizationContext::set($orgId);
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);

    // Capture contract events.
    $db->events = new \ArrayObject();
    $collector = new class ($db->events) implements ListenerInterface {
        public function __construct(private \ArrayObject $seen)
        {
        }

        public function handle(EventInterface $event): void
        {
            $this->seen[] = $event;
        }
    };
    $bus = $app->container->make(EventBus::class);
    $bus->listen(ContractActivated::class, $collector);
    $bus->listen(ContractTerminated::class, $collector);

    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->admin = $admin;
    $db->member = $member;
    $db->memberRoleId = $memberRoleId;
    $db->orgId = $orgId;
    $db->otherOrgId = $pdo->query("SELECT id FROM organizations WHERE id <> {$orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $db->currencyId = (int) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->ready = true;
};

$svc = fn (): ContractService => $db->c->make(ContractService::class);

/** Asserts a ValidationException carrying $key for $field. */
$expectError = function (callable $fn, string $field, string $key): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::true(in_array($key, $e->errors[$field] ?? [], true), "expected {$field} => {$key}, got " . json_encode($e->errors));

        return;
    }
    throw new LiteAssertionFailed("expected ValidationException {$field} => {$key}");
};

$active = function (string $title = 'LiteTest active', array $extra = []) use ($db, $svc): array {
    $c = $svc()->create($db->admin, array_merge(['title' => $title, 'start_date' => '2026-01-01'], $extra));

    return $svc()->activate($db->admin, (int) $c['id']);
};

return [
    'ContractService is exposed through ProvidesServices' => function () use ($setup, $db) {
        $setup();
        LiteAssert::true($db->c->has(ContractService::class));
        LiteAssert::true($db->c->make(ContractService::class) === $db->c->make(ContractService::class));
    },

    'create: draft owned by the creator, no number yet, timeline entry' => function () use ($setup, $db, $svc) {
        $setup();
        $c = $svc()->create($db->admin, ['title' => '  LiteTest one  ', 'value' => '1,250,000.50', 'currency_id' => (string) $db->currencyId, 'auto_renew' => '1', 'renewal_notice_days' => '30']);

        LiteAssert::same('LiteTest one', $c['title']);
        LiteAssert::same('draft', $c['status']);
        LiteAssert::same('draft', $c['effective_status']);
        LiteAssert::same((int) $db->admin['id'], (int) $c['owner_id']);
        LiteAssert::same(null, $c['contract_number']);
        LiteAssert::same('1250000.500000000000000000', (string) $c['value'], 'decimal kept exactly');
        LiteAssert::same(1, (int) $c['auto_renew']);
        LiteAssert::true(in_array('created', array_column((new TimelineEventRepository())->forSubject('contract', (int) $c['id']), 'type'), true));
    },

    'validation errors come back as translation keys' => function () use ($setup, $db, $svc, $expectError) {
        $setup();
        $expectError(fn () => $svc()->create($db->admin, ['title' => '']), 'title', 'contracts.error.title_required');
        $expectError(fn () => $svc()->create($db->admin, ['title' => str_repeat('x', 256)]), 'title', 'contracts.error.title_too_long');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'start_date' => '2026-02-30']), 'start_date', 'contracts.error.invalid_date');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'start_date' => '2026-05-01', 'end_date' => '2026-04-01']), 'end_date', 'contracts.error.end_before_start');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'value' => '12abc', 'currency_id' => (string) $db->currencyId]), 'value', 'contracts.error.invalid_value');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'value' => '100']), 'currency_id', 'contracts.error.currency_required');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'currency_id' => '999999']), 'currency_id', 'contracts.error.invalid_currency');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'category_id' => '999999']), 'category_id', 'contracts.error.invalid_category');
        $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'renewal_notice_days' => '9999']), 'renewal_notice_days', 'contracts.error.invalid_notice_days');
    },

    'tenant: another organization\'s contact or company is rejected' => function () use ($setup, $db, $svc, $expectError) {
        $setup();
        $foreignContact = $db->pdo->query('SELECT id FROM contacts WHERE organization_id <> ' . $db->orgId . ' LIMIT 1')->fetchColumn();
        $foreignCompany = $db->pdo->query('SELECT id FROM companies WHERE organization_id <> ' . $db->orgId . ' LIMIT 1')->fetchColumn();
        if ($foreignContact === false && $foreignCompany === false) {
            throw new LiteSkip('no contacts/companies in other organizations');
        }
        if ($foreignContact !== false) {
            $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'contact_id' => (string) $foreignContact]), 'contact_id', 'contracts.error.invalid_contact');
        }
        if ($foreignCompany !== false) {
            $expectError(fn () => $svc()->create($db->admin, ['title' => 'x', 'company_id' => (string) $foreignCompany]), 'company_id', 'contracts.error.invalid_company');
        }
    },

    'tenant: another organization\'s contract is not found' => function () use ($setup, $db, $svc) {
        $setup();
        if ($db->otherOrgId === false) {
            throw new LiteSkip('only one organization');
        }
        $db->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute([(int) $db->otherOrgId, 'LiteTest foreign', (int) $db->admin['id'], (int) $db->admin['id']]);
        $foreignId = (int) $db->pdo->lastInsertId();

        LiteAssert::throws(NotFoundException::class, fn () => $svc()->find($db->admin, $foreignId));
        LiteAssert::throws(NotFoundException::class, fn () => $svc()->update($db->admin, $foreignId, ['title' => 'hijack']));
        LiteAssert::throws(NotFoundException::class, fn () => $svc()->activate($db->admin, $foreignId));
        LiteAssert::same(0, count(array_filter($svc()->list($db->admin, ['search' => 'LiteTest foreign']))));
    },

    'RBAC: member creates and edits own; cannot edit someone else\'s or do admin actions' => function () use ($setup, $db, $svc) {
        $setup();
        $mine = $svc()->create($db->member, ['title' => 'LiteTest member own']);
        LiteAssert::same((int) $db->member['id'], (int) $mine['owner_id']);
        LiteAssert::same('LiteTest member edited', $svc()->update($db->member, (int) $mine['id'], ['title' => 'LiteTest member edited'])['title']);

        $admins = $svc()->create($db->admin, ['title' => 'LiteTest admin owned', 'start_date' => '2026-01-01']);
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->update($db->member, (int) $admins['id'], ['title' => 'x']));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->delete($db->member, (int) $mine['id']));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->activate($db->member, (int) $mine['id']));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->createCategory($db->member, 'LiteTest cat'));
    },

    'list respects view scope: organization vs own (SQL matches find())' => function () use ($setup, $db, $svc) {
        $setup();
        $svc()->create($db->admin, ['title' => 'LiteTest scope admin']);
        $svc()->create($db->member, ['title' => 'LiteTest scope member']);

        $titles = fn (array $u) => array_column($svc()->list($u, ['search' => 'LiteTest scope']), 'title');
        LiteAssert::same(2, count($titles($db->member)), 'organization scope sees both');

        $db->pdo->prepare("UPDATE role_permissions SET scope = 'own' WHERE role_id = ? AND permission_key = 'contracts.view'")->execute([$db->memberRoleId]);
        try {
            LiteAssert::same(['LiteTest scope member'], $titles($db->member), 'own scope sees only own');
            LiteAssert::same(1, $svc()->count($db->member, ['search' => 'LiteTest scope']));
            $other = $svc()->list($db->admin, ['search' => 'LiteTest scope admin'])[0];
            LiteAssert::throws(AuthorizationException::class, fn () => $svc()->find($db->member, (int) $other['id']));
        } finally {
            $db->pdo->prepare("UPDATE role_permissions SET scope = 'organization' WHERE role_id = ? AND permission_key = 'contracts.view'")->execute([$db->memberRoleId]);
        }
    },

    'a user without contracts.view cannot list' => function () use ($setup, $db, $svc) {
        $setup();
        $noRole = array_merge($db->member, ['role_id' => null]);
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->list($noRole));
    },

    'approval flow: submit → member cannot approve → admin approves → active with number' => function () use ($setup, $db, $svc) {
        $setup();
        $c = $svc()->create($db->member, ['title' => 'LiteTest approval', 'start_date' => '2026-03-01']);
        LiteAssert::same('pending_approval', $svc()->submit($db->member, (int) $c['id'])['status']);
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->approve($db->member, (int) $c['id']));

        $before = count($db->events);
        $approved = $svc()->approve($db->admin, (int) $c['id'], '2026-02-28');
        LiteAssert::same('active', $approved['status']);
        LiteAssert::same(1, preg_match('/^CTR-\d{4,}$/', (string) $approved['contract_number']), 'number format: ' . $approved['contract_number']);
        LiteAssert::same('2026-02-28 00:00:00', $approved['signed_at']);
        LiteAssert::true($db->events[$before] instanceof ContractActivated);
        LiteAssert::same($approved['contract_number'], $db->events[$before]->contractNumber);
    },

    'return to draft from pending (approver)' => function () use ($setup, $db, $svc) {
        $setup();
        $c = $svc()->create($db->admin, ['title' => 'LiteTest return']);
        $svc()->submit($db->admin, (int) $c['id']);
        LiteAssert::same('draft', $svc()->returnToDraft($db->admin, (int) $c['id'])['status']);
    },

    'activation needs a start date; numbers are sequential per organization' => function () use ($setup, $db, $svc, $expectError) {
        $setup();
        $noStart = $svc()->create($db->admin, ['title' => 'LiteTest no start']);
        $expectError(fn () => $svc()->activate($db->admin, (int) $noStart['id']), 'start_date', 'contracts.error.start_date_required_to_activate');

        $a = $svc()->activate($db->admin, (int) $svc()->create($db->admin, ['title' => 'LiteTest n1', 'start_date' => '2026-01-01'])['id']);
        $b = $svc()->activate($db->admin, (int) $svc()->create($db->admin, ['title' => 'LiteTest n2', 'start_date' => '2026-01-01'])['id']);
        $n = fn (array $c) => (int) substr((string) $c['contract_number'], 4);
        LiteAssert::same($n($a) + 1, $n($b));
    },

    'number series: each organization has its own sequence' => function () use ($setup, $db) {
        $setup();
        if ($db->otherOrgId === false) {
            throw new LiteSkip('only one organization');
        }
        $series = new NumberSeriesRepository();
        $first = OrganizationContext::runAs((int) $db->otherOrgId, fn () => $series->next('lite_test_doc', 'LT-'));
        $second = OrganizationContext::runAs((int) $db->otherOrgId, fn () => $series->next('lite_test_doc', 'LT-'));
        $mine = $series->next('lite_test_doc', 'LT-');

        LiteAssert::same('LT-0001', $first);
        LiteAssert::same('LT-0002', $second);
        LiteAssert::same('LT-0001', $mine, 'this org starts its own sequence');
    },

    'active contracts cannot be edited or deleted; drafts can be deleted' => function () use ($setup, $db, $svc, $active, $expectError) {
        $setup();
        $c = $active('LiteTest locked');
        $expectError(fn () => $svc()->update($db->admin, (int) $c['id'], ['title' => 'x']), 'status', 'contracts.error.not_editable');
        $expectError(fn () => $svc()->delete($db->admin, (int) $c['id']), 'status', 'contracts.error.not_deletable');

        $draft = $svc()->create($db->admin, ['title' => 'LiteTest delete me']);
        $svc()->delete($db->admin, (int) $draft['id']);
        LiteAssert::throws(NotFoundException::class, fn () => $svc()->find($db->admin, (int) $draft['id']));
    },

    'terminate: only an active contract; fires ContractTerminated' => function () use ($setup, $db, $svc, $active, $expectError) {
        $setup();
        $c = $active('LiteTest terminate');
        $before = count($db->events);
        $t = $svc()->terminate($db->admin, (int) $c['id'], 'Customer request');
        LiteAssert::same('terminated', $t['status']);
        LiteAssert::true($t['terminated_at'] !== null);
        LiteAssert::true($db->events[$before] instanceof ContractTerminated);

        $draft = $svc()->create($db->admin, ['title' => 'LiteTest not active']);
        $expectError(fn () => $svc()->terminate($db->admin, (int) $draft['id']), 'status', 'contracts.error.not_active');
    },

    'cancel: draft or pending only' => function () use ($setup, $db, $svc, $active, $expectError) {
        $setup();
        $draft = $svc()->create($db->admin, ['title' => 'LiteTest cancel']);
        LiteAssert::same('cancelled', $svc()->cancel($db->admin, (int) $draft['id'])['status']);
        $c = $active('LiteTest cannot cancel');
        $expectError(fn () => $svc()->cancel($db->admin, (int) $c['id']), 'status', 'contracts.error.invalid_transition');
    },

    'expiry is computed on read and drives filters, terminate and renew' => function () use ($setup, $db, $svc, $active, $expectError) {
        $setup();
        $c = $active('LiteTest expired', ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        LiteAssert::same('active', $c['status'], 'stored status');
        LiteAssert::same('expired', $c['effective_status'], 'computed status');

        $expired = array_column($svc()->list($db->admin, ['search' => 'LiteTest expired', 'effective_status' => 'expired']), 'id');
        $stillActive = array_column($svc()->list($db->admin, ['search' => 'LiteTest expired', 'effective_status' => 'active']), 'id');
        LiteAssert::true(in_array($c['id'], $expired, true));
        LiteAssert::false(in_array($c['id'], $stillActive, true));

        $expectError(fn () => $svc()->terminate($db->admin, (int) $c['id']), 'status', 'contracts.error.not_active');
    },

    'renew: new draft linked to the old one, dates follow on, owner = renewer' => function () use ($setup, $db, $svc, $active, $expectError) {
        $setup();
        $old = $active('LiteTest renew', ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'value' => '500', 'currency_id' => (string) $db->currencyId]);
        $new = $svc()->renew($db->admin, (int) $old['id']);

        LiteAssert::same('draft', $new['status']);
        LiteAssert::same('renewal', $new['relation']);
        LiteAssert::same((int) $old['id'], (int) $new['parent_contract_id']);
        LiteAssert::same('2026-01-01', $new['start_date']);
        LiteAssert::same('2026-12-31', $new['end_date']);
        LiteAssert::same(null, $new['contract_number']);
        LiteAssert::same((string) $old['value'], (string) $new['value']);
        LiteAssert::same('active', $svc()->find($db->admin, (int) $old['id'])['status'], 'old contract unchanged');

        $draft = $svc()->create($db->admin, ['title' => 'LiteTest draft no renew']);
        $expectError(fn () => $svc()->renew($db->admin, (int) $draft['id']), 'status', 'contracts.error.not_renewable');
    },

    'allowedActions reflects both permission and status' => function () use ($setup, $db, $svc, $active) {
        $setup();
        $draft = $svc()->create($db->member, ['title' => 'LiteTest actions']);
        $m = $svc()->allowedActions($db->member, $draft);
        LiteAssert::true($m['edit'] && $m['submit'] && $m['cancel']);
        LiteAssert::false($m['activate'] || $m['delete'] || $m['terminate'] || $m['renew'] || $m['approve']);

        $a = $svc()->allowedActions($db->admin, $active('LiteTest actions active'));
        LiteAssert::true($a['terminate'] && $a['renew']);
        LiteAssert::false($a['edit'] || $a['delete'] || $a['activate'] || $a['submit']);
    },

    'categories: create, unique name, rename, delete leaves contracts uncategorised' => function () use ($setup, $db, $svc, $expectError) {
        $setup();
        $cat = $svc()->createCategory($db->admin, 'LiteTest Service');
        $expectError(fn () => $svc()->createCategory($db->admin, 'LiteTest Service'), 'name', 'contracts.error.category_name_taken');
        $expectError(fn () => $svc()->createCategory($db->admin, '   '), 'name', 'contracts.error.category_name_invalid');

        $svc()->renameCategory($db->admin, (int) $cat['id'], 'LiteTest Lease');
        LiteAssert::true(in_array('LiteTest Lease', array_column($svc()->categories($db->admin), 'name'), true));

        $c = $svc()->create($db->admin, ['title' => 'LiteTest categorised', 'category_id' => (string) $cat['id']]);
        LiteAssert::same('LiteTest Lease', $svc()->list($db->admin, ['search' => 'LiteTest categorised'])[0]['category_name']);

        $svc()->deleteCategory($db->admin, (int) $cat['id']);
        LiteAssert::same(null, $svc()->find($db->admin, (int) $c['id'])['category_id']);
    },

    'rollback leaves no test data behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contract_categories WHERE name LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM number_series")->fetchColumn());
        LiteAssert::same('organization', $db->pdo->query("SELECT scope FROM role_permissions WHERE role_id = {$db->memberRoleId} AND permission_key = 'contracts.view'")->fetchColumn());
    },
];
