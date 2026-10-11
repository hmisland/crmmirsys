<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — ProjectService: CRUD + validation, PRJ numbering,
 * every lifecycle transition (allowed and refused), members and the
 * membership visibility rule, events. crm_local only, one transaction,
 * rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Events\EventBus;
use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use Modules\Projects\Events\ProjectCreated;
use Modules\Projects\Events\ProjectStatusChanged;
use Modules\Projects\Services\ProjectService;
use Tests\Lite\Support\ProjectsLite;

$booter = ProjectsLite::booter();
$events = new \ArrayObject();
$boot = function () use ($booter, $events) {
    static $listening = false;
    $a = $booter();
    if (!$listening) {
        $collector = new class ($events) implements ListenerInterface {
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function handle(EventInterface $event): void
            {
                $this->seen[] = $event;
            }
        };
        $bus = $a->container()->make(EventBus::class);
        $bus->listen(ProjectCreated::class, $collector);
        $bus->listen(ProjectStatusChanged::class, $collector);
        $listening = true;
    }

    return $a;
};
$svc = fn ($a): ProjectService => ProjectsLite::service($a);

$expectError = function (callable $fn, string $field, string $key): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::true(in_array($key, $e->errors[$field] ?? [], true), "expected {$field}: {$key}, got " . json_encode($e->errors));

        return;
    }
    throw new LiteAssertionFailed("expected ValidationException {$field}: {$key}");
};

/** Moves a project into $status through the real transitions. */
$into = function ($a, int $id, string $status) use ($svc): void {
    $path = [
        'draft' => [], 'planned' => ['plan'], 'active' => ['start'], 'on_hold' => ['start', 'hold'],
        'completed' => ['start', 'complete'], 'cancelled' => ['cancel'],
    ][$status];
    foreach ($path as $action) {
        $svc($a)->changeStatus($a->admin, $id, $action);
    }
};

return [
    'create: draft, PRJ- code from the Core number series, creator is manager, timeline + ProjectCreated' => function () use ($boot, $svc, $events) {
        $a = $boot();
        $events->exchangeArray([]);
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest P1', 'contact_id' => (string) ProjectsLite::contactId($a), 'start_date' => '2026-01-01', 'expected_end_date' => '2026-06-30', 'description' => 'desc']);
        LiteAssert::same('draft', $p['status']);
        LiteAssert::same(1, preg_match('/^PRJ-\d{4,}$/', $p['code']), 'code ' . $p['code']);
        LiteAssert::same((int) $a->admin['id'], (int) $p['owner_id']);
        LiteAssert::same((int) $a->admin['id'], (int) $p['created_by_user_id']);
        LiteAssert::same($a->orgId, (int) $p['organization_id']);
        LiteAssert::same('2026-06-30', $p['expected_end_date']);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p['id']}")->fetchColumn(), 'timeline');
        LiteAssert::same(1, count($events), 'one event');
        LiteAssert::true($events[0] instanceof ProjectCreated && $events[0]->projectId === (int) $p['id'] && $events[0]->code === $p['code'] && $events[0]->organizationId === $a->orgId);
    },

    'numbering is sequential per organization' => function () use ($boot, $svc) {
        $a = $boot();
        $n1 = (int) substr($svc($a)->create($a->admin, ['name' => 'LiteTest seq 1'])['code'], 4);
        $n2 = (int) substr($svc($a)->create($a->admin, ['name' => 'LiteTest seq 2'])['code'], 4);
        LiteAssert::same($n1 + 1, $n2);
    },

    'validation: name required/too long, bad dates, end before start, foreign/unknown contact, company, manager' => function () use ($boot, $svc, $expectError) {
        $a = $boot();
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => '  ']), 'name', 'projects.error.name_required');
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => str_repeat('x', 256)]), 'name', 'projects.error.name_too_long');
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'start_date' => '2026-02-30']), 'start_date', 'projects.error.invalid_date');
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'start_date' => '2026-05-01', 'expected_end_date' => '2026-04-01']), 'expected_end_date', 'projects.error.end_before_start');
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'contact_id' => '999999999']), 'contact_id', 'projects.error.invalid_contact');
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'company_id' => '999999999']), 'company_id', 'projects.error.invalid_company');
        $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'owner_id' => '999999999']), 'owner_id', 'projects.error.invalid_owner');
        if ($a->otherOrgId !== null) {
            $foreignUser = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
            if ($foreignUser !== false) {
                $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'owner_id' => (string) $foreignUser]), 'owner_id', 'projects.error.invalid_owner');
            }
            $foreignContact = $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
            if ($foreignContact !== false) {
                $expectError(fn () => $svc($a)->create($a->admin, ['name' => 'x', 'contact_id' => (string) $foreignContact]), 'contact_id', 'projects.error.invalid_contact');
            }
        }
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name = 'x'")->fetchColumn(), 'nothing saved');
    },

    'manager can be another active user of the org; team/department follow the manager' => function () use ($boot, $svc) {
        $a = $boot();
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest owner', 'owner_id' => (string) $a->member['id']]);
        $real = $a->pdo->query("SELECT team_id, department_id FROM users WHERE id = {$a->member['id']}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same((int) $a->member['id'], (int) $p['owner_id']);
        LiteAssert::same($real['team_id'] === null ? null : (int) $real['team_id'], $p['team_id'] === null ? null : (int) $p['team_id']);
        LiteAssert::same($real['department_id'] === null ? null : (int) $real['department_id'], $p['department_id'] === null ? null : (int) $p['department_id']);
    },

    'update: fields change, timeline written; code and status untouched' => function () use ($boot, $svc) {
        $a = $boot();
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest upd']);
        $u = $svc($a)->update($a->admin, (int) $p['id'], ['name' => 'LiteTest upd 2', 'company_id' => (string) ProjectsLite::companyId($a), 'expected_end_date' => '2027-01-01']);
        LiteAssert::same('LiteTest upd 2', $u['name']);
        LiteAssert::same($p['code'], $u['code']);
        LiteAssert::same('draft', $u['status']);
        LiteAssert::same('2027-01-01', $u['expected_end_date']);
    },

    'lifecycle: every allowed transition, actual_end_date on complete, cleared on reopen, ProjectStatusChanged each time' => function () use ($boot, $svc, $events) {
        $a = $boot();
        $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest life'])['id'];
        $events->exchangeArray([]);
        $steps = [['plan', 'planned'], ['hold', 'on_hold'], ['resume', 'active'], ['hold', 'on_hold'], ['resume', 'active'], ['complete', 'completed'], ['reopen', 'active'], ['complete', 'completed']];
        $prev = 'draft';
        foreach ($steps as $i => [$action, $to]) {
            $p = $svc($a)->changeStatus($a->admin, $id, $action, $action === 'complete' && $i === 5 ? '2026-03-15' : null);
            LiteAssert::same($to, $p['status'], $action);
            LiteAssert::true($events[$i] instanceof ProjectStatusChanged && $events[$i]->fromStatus === $prev && $events[$i]->toStatus === $to, "event {$action}");
            if ($action === 'complete') {
                LiteAssert::same($i === 5 ? '2026-03-15' : date('Y-m-d'), $p['actual_end_date'], 'actual end date');
            }
            if ($action === 'reopen') {
                LiteAssert::same(null, $p['actual_end_date'], 'cleared on reopen');
            }
            $prev = $to;
        }
        $direct = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest life 2'])['id'];
        LiteAssert::same('active', $svc($a)->changeStatus($a->admin, $direct, 'start')['status'], 'draft → active directly');
        LiteAssert::same('cancelled', $svc($a)->changeStatus($a->admin, $direct, 'cancel')['status'], 'active → cancelled');
    },

    'lifecycle: every refused transition leaves the status unchanged' => function () use ($boot, $svc, $into, $expectError) {
        $a = $boot();
        $allowed = [];
        foreach (ProjectService::TRANSITIONS as $action => [$from]) {
            foreach ($from as $status) {
                $allowed["{$status}:{$action}"] = true;
            }
        }
        foreach (ProjectService::STATUSES as $status) {
            foreach (array_keys(ProjectService::TRANSITIONS) as $action) {
                if (isset($allowed["{$status}:{$action}"])) {
                    continue;
                }
                $id = (int) $svc($a)->create($a->admin, ['name' => "LiteTest refuse {$status} {$action}"])['id'];
                $into($a, $id, $status);
                $expectError(fn () => $svc($a)->changeStatus($a->admin, $id, $action), 'status', 'projects.error.invalid_transition');
                LiteAssert::same($status, $a->pdo->query("SELECT status FROM projects WHERE id = {$id}")->fetchColumn(), "{$status} after refused {$action}");
            }
        }
        $id = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest refuse unknown'])['id'];
        $expectError(fn () => $svc($a)->changeStatus($a->admin, $id, 'archive'), 'status', 'projects.error.invalid_transition');
        $into($a, $id, 'active');
        $expectError(fn () => $svc($a)->changeStatus($a->admin, $id, 'complete', '2026-13-01'), 'actual_end_date', 'projects.error.invalid_date');
    },

    'edit only while draft/planned/active/on_hold; delete only draft/planned/cancelled' => function () use ($boot, $svc, $into, $expectError) {
        $a = $boot();
        foreach (ProjectService::STATUSES as $status) {
            $id = (int) $svc($a)->create($a->admin, ['name' => "LiteTest ed {$status}"])['id'];
            $into($a, $id, $status);
            $p = $svc($a)->find($a->admin, $id);
            $actions = $svc($a)->allowedActions($a->admin, $p);
            $editable = in_array($status, ['draft', 'planned', 'active', 'on_hold'], true);
            $deletable = in_array($status, ['draft', 'planned', 'cancelled'], true);
            LiteAssert::same($editable, $actions['edit'], "edit flag {$status}");
            LiteAssert::same($deletable, $actions['delete'], "delete flag {$status}");
            if (!$editable) {
                $expectError(fn () => $svc($a)->update($a->admin, $id, ['name' => 'nope']), 'status', 'projects.error.not_editable');
            }
            if ($deletable) {
                $svc($a)->delete($a->admin, $id);
                LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE id = {$id}")->fetchColumn(), "deleted {$status}");
            } else {
                $expectError(fn () => $svc($a)->delete($a->admin, $id), 'status', 'projects.error.not_deletable');
            }
        }
    },

    'member role: creates and edits own projects; cannot edit, change status, delete or manage members of others' => function () use ($boot, $svc) {
        $a = $boot();
        $m = $a->member;
        $mine = $svc($a)->create($m, ['name' => 'LiteTest member own']);
        LiteAssert::same((int) $m['id'], (int) $mine['owner_id']);
        $svc($a)->update($m, (int) $mine['id'], ['name' => 'LiteTest member own 2']);
        $svc($a)->changeStatus($m, (int) $mine['id'], 'start');
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->delete($m, (int) $mine['id']), 'member cannot delete');

        $other = $svc($a)->create($a->admin, ['name' => 'LiteTest admin owned']);
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->update($m, (int) $other['id'], ['name' => 'x']));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->changeStatus($m, (int) $other['id'], 'start'));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->addMember($m, (int) $other['id'], (int) $m['id']));
        LiteAssert::false($svc($a)->allowedActions($m, $other)['edit']);
        LiteAssert::true($svc($a)->find($m, (int) $other['id'])['id'] === $other['id'], 'view at organization scope');
    },

    'members: add (active org user only, idempotent), list, remove; timeline entries' => function () use ($boot, $svc, $expectError) {
        $a = $boot();
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest members']);
        $id = (int) $p['id'];
        LiteAssert::true(in_array((int) $a->member['id'], array_map(fn ($u) => (int) $u['id'], $svc($a)->memberCandidates($a->admin, $p)), true), 'candidate');
        LiteAssert::false(in_array((int) $a->admin['id'], array_map(fn ($u) => (int) $u['id'], $svc($a)->memberCandidates($a->admin, $p)), true), 'manager is not a candidate');
        $row = $svc($a)->addMember($a->admin, $id, (int) $a->member['id']);
        $again = $svc($a)->addMember($a->admin, $id, (int) $a->member['id']);
        LiteAssert::same($row['id'], $again['id'], 'idempotent');
        LiteAssert::same(1, count($svc($a)->members($a->admin, $id)));
        $expectError(fn () => $svc($a)->addMember($a->admin, $id, 999999999), 'user_id', 'projects.error.invalid_member');
        if ($a->otherOrgId !== null) {
            $foreignUser = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
            if ($foreignUser !== false) {
                $expectError(fn () => $svc($a)->addMember($a->admin, $id, (int) $foreignUser), 'user_id', 'projects.error.invalid_member');
            }
        }
        LiteAssert::throws(NotFoundException::class, fn () => $svc($a)->removeMember($a->admin, $id, 999999999));
        $svc($a)->removeMember($a->admin, $id, (int) $row['id']);
        LiteAssert::same(0, count($svc($a)->members($a->admin, $id)));
        LiteAssert::same(2, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$id} AND type IN ('member_added', 'member_removed')")->fetchColumn());
    },

    'membership widens VIEW only: own-scope member sees projects they belong to, still cannot edit them' => function () use ($boot, $svc) {
        $a = $boot();
        $m = $a->member;
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest scoped A']);
        $q = $svc($a)->create($a->admin, ['name' => 'LiteTest scoped B']);
        $a->pdo->prepare("UPDATE role_permissions SET scope = 'own' WHERE role_id = ? AND permission_key = 'projects.view'")->execute([$a->memberRoleId]);
        try {
            $names = fn () => array_column($svc($a)->list($m, ['search' => 'LiteTest scoped']), 'name');
            LiteAssert::same([], $names(), 'own scope, not a member: nothing');
            LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->find($m, (int) $p['id']));

            $svc($a)->addMember($a->admin, (int) $p['id'], (int) $m['id']);
            LiteAssert::same(['LiteTest scoped A'], $names(), 'member: sees A only');
            LiteAssert::same(1, $svc($a)->count($m, ['search' => 'LiteTest scoped']));
            LiteAssert::same('LiteTest scoped A', $svc($a)->find($m, (int) $p['id'])['name']);
            LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->find($m, (int) $q['id']));
            LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->update($m, (int) $p['id'], ['name' => 'hijack']), 'membership never grants edit');
            LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->changeStatus($m, (int) $p['id'], 'start'));
            $actions = $svc($a)->allowedActions($m, $svc($a)->find($m, (int) $p['id']));
            LiteAssert::false($actions['edit'] || $actions['start'] || $actions['manage_members'] || $actions['delete'], 'no write action for a mere member');

            // "Mine" = managed or member-of
            $mine = $svc($a)->create($m, ['name' => 'LiteTest scoped mine']);
            LiteAssert::same(['LiteTest scoped mine', 'LiteTest scoped A'], array_column($svc($a)->list($m, ['search' => 'LiteTest scoped', 'involving_user_id' => (int) $m['id']]), 'name'));
            LiteAssert::same(2, count($svc($a)->list($m, ['search' => 'LiteTest scoped'])), 'own + member-of');
            unset($mine);
        } finally {
            $a->pdo->prepare("UPDATE role_permissions SET scope = 'organization' WHERE role_id = ? AND permission_key = 'projects.view'")->execute([$a->memberRoleId]);
        }
    },

    'a user without a role is refused everywhere' => function () use ($boot, $svc) {
        $a = $boot();
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest norole']);
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->list($a->noRole));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->find($a->noRole, (int) $p['id']));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->create($a->noRole, ['name' => 'x']));
    },

    'filters: search by name/code, status, contact, mine' => function () use ($boot, $svc) {
        $a = $boot();
        $contact = ProjectsLite::contactId($a);
        $p = $svc($a)->create($a->admin, ['name' => 'LiteTest filter zeta', 'contact_id' => (string) $contact]);
        $svc($a)->changeStatus($a->admin, (int) $p['id'], 'plan');
        LiteAssert::same(1, $svc($a)->count($a->admin, ['search' => 'filter zeta']));
        LiteAssert::same(1, $svc($a)->count($a->admin, ['search' => $p['code']]));
        LiteAssert::same(1, $svc($a)->count($a->admin, ['search' => 'filter zeta', 'status' => 'planned']));
        LiteAssert::same(0, $svc($a)->count($a->admin, ['search' => 'filter zeta', 'status' => 'active']));
        LiteAssert::same(1, $svc($a)->count($a->admin, ['search' => 'filter zeta', 'contact_id' => $contact]));
        LiteAssert::same(0, $svc($a)->count($a->member, ['search' => 'filter zeta', 'involving_user_id' => (int) $a->member['id']]));
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest%'")->fetchColumn());
    },
];
