<?php

declare(strict_types=1);

/**
 * P1 timezone backlog (docs/organization-timezone-design.md §4): business
 * logic on organization-local wall-clock values (C3) and business dates.
 * A fixed clock is injected into OrganizationTimezone; organization A is
 * Asia/Tehran (+03:30), B is Asia/Baku (+04:00), a third case uses
 * Europe/Berlin for DST. crm_local, one rolled-back transaction.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Appointments\Services\AvailabilityService;
use Modules\Appointments\Services\CalendarService;
use Modules\Contracts\Services\ContractService;
use Modules\Finance\Services\InstallmentService;
use Modules\Followups\FollowUpReminderRepository;
use Modules\Followups\FollowUpRepository;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    $a = LocalApp::boot();
    if ($a->otherOrgId === null) {
        $a->rollBack();
        throw new LiteSkip('needs a second organization');
    }
    $a->as($a->admin);
    $a->locale('en');
    $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Tehran' WHERE id = {$a->orgId}");
    $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Baku' WHERE id = {$a->otherOrgId}");
    $h->app = $a;

    return $a;
};

/** Builds a service from its constructor types (container first; defaults where given). */
$build = function (string $class) use (&$build, $h): object {
    try {
        return $h->app->container()->make($class);
    } catch (\ArgumentCountError) {
        $ref = new \ReflectionClass($class);
        $args = [];
        foreach ($ref->getConstructor()->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $build($type->getName());
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } else {
                throw new LiteSkip("cannot build {$class}");
            }
        }

        return $ref->newInstanceArgs($args);
    }
};

/** Pins "now" (UTC) for every OrganizationTimezone::shared() caller. */
$at = function (string $utc) use ($h): void {
    $pdo = $h->app->pdo;
    OrganizationTimezone::setShared(new OrganizationTimezone(
        fn (int $id) => $pdo->query('SELECT default_timezone FROM organizations WHERE id = ' . $id)->fetchColumn() ?: null,
        'Asia/Tehran',
        fn () => new \DateTimeImmutable($utc, new \DateTimeZone('UTC')),
    ));
};

$followUp = function (int $orgId, string $title, ?string $dueAt) use ($h): int {
    $a = $h->app;
    $a->pdo->prepare("INSERT INTO follow_ups (organization_id, title, owner_id, created_by_user_id, status, due_at) VALUES (?, ?, ?, ?, 'pending', ?)")
        ->execute([$orgId, $title, (int) $a->admin['id'], (int) $a->admin['id'], $dueAt]);

    return (int) $a->pdo->lastInsertId();
};

return [
    // --- Follow-ups --------------------------------------------------------------------

    'BL1 follow-ups overdue: a local deadline is overdue by the organization\'s clock (Tehran vs Baku)' => function () use ($boot, $at, $followUp) {
        $a = $boot();
        $followUp($a->orgId, 'LiteBL due A', '2026-10-15 10:00:00');
        $followUp($a->otherOrgId, 'LiteBL due B', '2026-10-15 10:00:00');
        $repo = $a->container()->make(FollowUpRepository::class);
        $overdue = fn (int $org) => array_values(array_filter(array_column(OrganizationContext::runAs($org, fn () => $repo->search(['overdue_only' => true])), 'title'), fn ($t) => str_starts_with((string) $t, 'LiteBL due')));
        try {
            $at('2026-10-15 05:59:00'); // Tehran 09:29, Baku 09:59
            LiteAssert::same([[], []], [$overdue($a->orgId), $overdue($a->otherOrgId)]);
            $at('2026-10-15 06:15:00'); // Tehran 09:45, Baku 10:15
            LiteAssert::same([[], ['LiteBL due B']], [$overdue($a->orgId), $overdue($a->otherOrgId)]);
            $at('2026-10-15 06:31:00'); // Tehran 10:01
            LiteAssert::same(['LiteBL due A'], $overdue($a->orgId));
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    'BL2 follow-up page and list mark "Overdue" by the organization\'s clock' => function () use ($boot, $at, $followUp) {
        $a = $boot();
        $id = $followUp($a->orgId, 'LiteBL page', '2026-10-15 10:00:00');
        try {
            $at('2026-10-15 06:15:00'); // Tehran 09:45 — UTC would already say overdue for a 06:00 deadline, not this one
            $r = $a->request('GET', "/followups/{$id}");
            if ($r['status'] !== 200) {
                throw new LiteSkip('Follow-ups module not available locally');
            }
            LiteAssert::notContains('>Overdue<', $r['body']);
            $at('2026-10-15 06:31:00');
            LiteAssert::contains('>Overdue<', $a->request('GET', "/followups/{$id}")['body']);
            LiteAssert::contains('2026-10-15 10:00:00 (Overdue)', $a->request('GET', '/followups')['body'], 'list');
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    'BL3 reminders: due by each follow-up organization\'s local clock; DST-correct for Berlin' => function () use ($boot, $at, $followUp) {
        $a = $boot();
        $repo = $a->container()->make(FollowUpReminderRepository::class);
        $add = function (int $org, string $title, string $scheduled) use ($a, $followUp): int {
            $fid = $followUp($org, $title, null);
            $a->pdo->prepare("INSERT INTO follow_up_reminders (follow_up_id, channel, offset_minutes, scheduled_at, status) VALUES (?, 'in_app', 0, ?, 'pending')")->execute([$fid, $scheduled]);

            return (int) $a->pdo->lastInsertId();
        };
        $ra = $add($a->orgId, 'LiteBL rem A', '2026-10-15 10:00:00');
        $rb = $add($a->otherOrgId, 'LiteBL rem B', '2026-10-15 10:00:00');
        $due = fn () => array_values(array_intersect(array_map('intval', array_column($repo->duePending(OrganizationTimezone::shared()), 'id')), [$ra, $rb]));
        try {
            $at('2026-10-15 06:15:00'); // Tehran 09:45, Baku 10:15
            LiteAssert::same([$rb], $due());
            $at('2026-10-15 06:30:00'); // Tehran 10:00
            LiteAssert::same([$ra, $rb], $due());

            // Berlin on the spring DST day: 03:30 local = 01:30 UTC (02:00–03:00 does not exist).
            $a->pdo->exec("UPDATE organizations SET default_timezone = 'Europe/Berlin' WHERE id = {$a->otherOrgId}");
            $rd = $add($a->otherOrgId, 'LiteBL rem DST', '2026-03-29 03:30:00');
            $dueDst = fn () => in_array($rd, array_map('intval', array_column($repo->duePending(OrganizationTimezone::shared()), 'id')), true);
            $at('2026-03-29 01:29:00');
            LiteAssert::false($dueDst(), 'Berlin 03:29');
            $at('2026-03-29 01:30:00');
            LiteAssert::true($dueDst(), 'Berlin 03:30');
        } finally {
            $a->pdo->exec("UPDATE organizations SET default_timezone = 'Asia/Baku' WHERE id = {$a->otherOrgId}");
            OrganizationTimezone::setShared(null);
        }
    },

    'BL4 rule engine: an automatic follow-up is due N hours from the organization\'s local now; reminder before it' => function () use ($boot, $at, $build) {
        $a = $boot();
        $deal = $a->pdo->query("SELECT id FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
        if ($deal === false) {
            throw new LiteSkip('needs a deal in the organization');
        }
        try {
            $listener = $build(\Modules\Followups\Listeners\FollowUpRuleEngineListener::class);
        } catch (\Throwable $e) {
            throw new LiteSkip('listener not constructible: ' . $e->getMessage());
        }
        $stage = 987650 + random_int(1, 9);
        $a->pdo->prepare("INSERT INTO follow_up_rules (organization_id, name, event_type, conditions_json, action_configuration_json, enabled, created_by_user_id) VALUES (?, 'LiteBL rule', 'DealStageChanged', ?, ?, 1, ?)")
            ->execute([$a->orgId, json_encode(['stage_id' => $stage]), json_encode(['title' => 'LiteBL auto', 'due_in_hours' => 2, 'reminder_minutes_before' => 30, 'assign_to' => 'specific_user', 'assign_to_user_id' => (int) $a->admin['id']]), (int) $a->admin['id']]);
        try {
            $at('2026-10-15 05:00:00'); // Tehran 08:30
            OrganizationContext::runAs($a->orgId, fn () => $listener->handle(new \Modules\Sales\Events\DealStageChanged($a->orgId, (int) $deal, $stage, 'x', false, false, (int) $a->admin['id'])));
            $row = $a->pdo->query("SELECT id, due_at FROM follow_ups WHERE title = 'LiteBL auto' ORDER BY id DESC LIMIT 1")->fetch();
            LiteAssert::same('2026-10-15 10:30:00', $row['due_at'], 'Tehran local 08:30 + 2 h');
            LiteAssert::same('2026-10-15 10:00:00', $a->pdo->query('SELECT scheduled_at FROM follow_up_reminders WHERE follow_up_id = ' . (int) $row['id'])->fetchColumn());
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    // --- Client records ------------------------------------------------------------------

    'BL5 client records: next / last visit and "upcoming in 7 days" use the organization\'s local now' => function () use ($boot, $at) {
        $a = $boot();
        $contact = $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
        if ($contact === false) {
            throw new LiteSkip('needs a contact');
        }
        $a->pdo->prepare('INSERT INTO client_records (organization_id, contact_id, owner_id) VALUES (?, ?, ?)')->execute([$a->orgId, $contact, (int) $a->admin['id']]);
        $record = (int) $a->pdo->lastInsertId();
        $session = $a->pdo->prepare('INSERT INTO client_record_sessions (organization_id, client_record_id, session_at, status, created_by_user_id) VALUES (?, ?, ?, ?, ?)');
        $session->execute([$a->orgId, $record, '2026-10-15 10:00:00', 'scheduled', (int) $a->admin['id']]);
        $session->execute([$a->orgId, $record, '2026-10-15 08:00:00', 'completed', (int) $a->admin['id']]);
        $repo = $a->container()->make(\Modules\ClientRecords\ClientRecordRepository::class);
        $next = fn () => (array_values(array_filter($repo->search([]), fn ($r) => (int) $r['id'] === $record))[0] ?? [])['next_visit_at'] ?? null;
        $sessions = $a->pdo->query("SELECT * FROM client_record_sessions WHERE client_record_id = {$record}")->fetchAll();
        $summary = function () use ($sessions): array {
            $controller = (new \ReflectionClass(\Modules\ClientRecords\Controllers\ClientRecordController::class))->newInstanceWithoutConstructor();
            $m = new \ReflectionMethod($controller, 'summary');

            return $m->invoke($controller, [], $sessions, []);
        };
        try {
            $at('2026-10-15 06:15:00'); // Tehran 09:45: the 10:00 session is still ahead
            LiteAssert::same('2026-10-15 10:00:00', $next());
            $s = $summary();
            LiteAssert::same('2026-10-15 10:00:00', $s['next_visit']['session_at'] ?? null);
            LiteAssert::same('2026-10-15 08:00:00', $s['last_visit']['session_at'] ?? null);
            $at('2026-10-15 06:31:00'); // Tehran 10:01: it has passed
            LiteAssert::same(null, $next());
            LiteAssert::same(null, $summary()['next_visit'] ?? null);
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    // --- Appointments -------------------------------------------------------------------------

    'BL6 appointments: past slots are cut at the organization\'s local now (Tehran vs Baku)' => function () use ($boot, $at) {
        $a = $boot();
        $availability = $a->container()->make(AvailabilityService::class);
        $resource = ['id' => 999999901, 'capacity' => 1];
        try {
            $at('2026-10-15 06:00:00'); // Tehran 09:30, Baku 10:00
            $tehran = OrganizationContext::runAs($a->orgId, fn () => $availability->freeSlots($resource, '2026-10-15', 30));
            $baku = OrganizationContext::runAs($a->otherOrgId, fn () => $availability->freeSlots($resource, '2026-10-15', 30));
            LiteAssert::same('09:30', $tehran[0] ?? null, 'Tehran: from 09:30');
            LiteAssert::same('10:00', $baku[0] ?? null, 'Baku: from 10:00');
            LiteAssert::false(in_array('09:15', $tehran, true));
            LiteAssert::same('08:00', OrganizationContext::runAs($a->orgId, fn () => $availability->freeSlots($resource, '2026-10-16', 30))[0] ?? null, 'tomorrow: whole day');
            LiteAssert::same([], OrganizationContext::runAs($a->orgId, fn () => $availability->freeSlots($resource, '2026-10-14', 30)), 'yesterday: nothing');
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    'BL7 appointments calendar: "today" is the organization\'s date (UTC is still yesterday)' => function () use ($boot, $at, $build) {
        $a = $boot();
        $calendar = $build(CalendarService::class);
        try {
            $at('2026-12-31 21:00:00'); // Tehran 2027-01-01 00:30 (far from the real date, so the server clock can't pass by chance)
            $week = OrganizationContext::runAs($a->orgId, fn () => $calendar->week('2026-12-28', []));
            $today = array_keys(array_filter($week, fn ($d) => $d['is_today']));
            LiteAssert::same(['2027-01-01'], array_values(array_map(fn ($k) => is_string($k) ? $k : $week[$k]['date'], $today)));
            $r = $a->outcome('GET', '/appointments/calendar') === 'ok' ? $a->request('GET', '/appointments/calendar') : ['status' => 0];
            if ($r['status'] === 200) {
                LiteAssert::contains('date=2027-01-01', $r['body'], '"Today" button and default day');
            }
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    // --- Business dates -------------------------------------------------------------------------

    'BL8 contracts and installments: "expired" / "overdue" switch at the organization\'s midnight' => function () use ($boot, $at) {
        $a = $boot();
        $contract = ['status' => 'active', 'end_date' => '2026-10-14'];
        try {
            $at('2026-10-14 20:29:00'); // Tehran 23:59 on the 14th
            LiteAssert::same('active', $a->container()->make(ContractService::class)->effectiveStatus($contract));
            LiteAssert::same('active', InstallmentService::contractStatus($contract));
            $at('2026-10-14 20:31:00'); // Tehran 00:01 on the 15th — still the 14th in UTC
            LiteAssert::same('expired', $a->container()->make(ContractService::class)->effectiveStatus($contract));
            LiteAssert::same('expired', InstallmentService::contractStatus($contract), 'Finance stays identical to Contracts');
            OrganizationContext::runAs($a->otherOrgId, function () use ($a, $at, $contract) {
                $at('2026-10-14 19:59:00'); // Baku 23:59
                LiteAssert::same('active', $a->container()->make(ContractService::class)->effectiveStatus($contract));
            });
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    'BL9 wallet gift codes expire at the redeeming organization\'s midnight' => function () use ($boot, $at, $build) {
        $a = $boot();
        $currency = $a->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        if ($currency === false) {
            throw new LiteSkip('needs a currency');
        }
        try {
            $service = $build(\Modules\Wallet\Services\WalletGiftService::class);
        } catch (\Throwable $e) {
            throw new LiteSkip('wallet not available: ' . $e->getMessage());
        }
        $a->pdo->prepare("INSERT INTO wallet_gift_codes (code, amount, currency_id, status, expires_at) VALUES ('GIFT-LTBL-TEST-ZONE', 1, ?, 'active', '2026-10-14')")->execute([$currency]);
        try {
            $at('2026-10-14 20:31:00'); // Tehran 15th, UTC 14th
            try {
                $service->redeem($a->admin, 'GIFT-LTBL-TEST-ZONE');
                LiteAssert::true(false, 'should be expired');
            } catch (ValidationException $e) {
                LiteAssert::same(['wallet_gift.error_expired'], $e->errors['code'] ?? null);
            }
            $at('2026-10-14 20:29:00'); // Tehran 23:59 on the 14th: not expired (redeem may succeed; rolled back)
            try {
                $service->redeem($a->admin, 'GIFT-LTBL-TEST-ZONE');
            } catch (ValidationException $e) {
                LiteAssert::true(($e->errors['code'] ?? []) !== ['wallet_gift.error_expired'], 'not expired yet');
            } catch (\PDOException) {
                // Past the expiry check: crediting opens its own transaction inside this test's one.
            }
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    'BL10 form defaults and generated documents use the organization\'s date' => function () use ($boot, $at) {
        $a = $boot();
        try {
            $at('2026-12-31 21:00:00'); // Tehran 2027-01-01 00:30, UTC still 2026-12-31 (and far from the real date)
            LiteAssert::same('2027-01-01', \App\Infrastructure\Theme\local_today());
            LiteAssert::same('2027-01-01 00:30:00', \App\Infrastructure\Theme\local_now());
            $checked = 0;
            foreach (['/finance/expenses/create' => 'name="expense_date" value="2027-01-01"', '/finance/invoices/create' => 'name="issue_date" value="2027-01-01"'] as $path => $needle) {
                $r = $a->request('GET', $path);
                if ($r['status'] === 200) {
                    LiteAssert::contains($needle, $r['body'], $path);
                    $checked++;
                }
            }
            if ($checked === 0) {
                throw new LiteSkip('Finance pages not available locally');
            }
        } finally {
            OrganizationTimezone::setShared(null);
        }
    },

    'cleanup: roll back everything' => function () use ($h) {
        OrganizationTimezone::setShared(null);
        if ($h->app !== null) {
            $h->app->rollBack();
        }
        LiteAssert::true(true);
    },
];
