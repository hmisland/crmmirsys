<?php

declare(strict_types=1);

/**
 * Marketing Phase 2 — final audit regressions: every built-in filter,
 * date/time boundaries on the database clock, stored rules tampered with
 * in the database, the frozen snapshot of a large dynamic segment, personal
 * values in rules hidden from users without contacts.view, and dashboard
 * time windows. crm_local only, one transaction, rolled back; SMS goes to
 * FakeSmsSender.
 */

use App\Application\Services\SmsService;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use Modules\Marketing\Segments\SegmentAudience;
use Modules\Marketing\Segments\SegmentConditionRegistry;
use Modules\Marketing\Segments\SegmentRules;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\InsightsService;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\SegmentService;
use Tests\Lite\Fixtures\FakeSmsSender;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Fixtures/MarketingFakes.php';

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
        $h->a->pdo->query('SELECT audience_snapshot FROM marketing_campaigns LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or Phase 2 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    if ($a->member === null) {
        $h->reason = 'needs a second active user in the organization';
        throw new LiteSkip($h->reason);
    }
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();

    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email')");
    foreach (['marketing', 'marketing_email'] as $key) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $key]);
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $pdo->lastInsertId()]);
    }
    $pdo->exec('DELETE FROM rate_limit_hits');
    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);

    // Every filter: Ana / Bob / Cam differ on each field. created_at uses the DATABASE clock.
    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteAud G']);
    $h->group = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tags (organization_id, name) VALUES (?, 'LiteAud T')")->execute([$a->orgId]);
    $h->tag = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO companies (organization_id, name) VALUES (?, 'LiteAud Co')")->execute([$a->orgId]);
    $company = (int) $pdo->lastInsertId();
    $h->c = [];
    $add = function (string $last, string $first, array $cols, string $createdSql) use ($a, $pdo, $h): int {
        $cols = ['organization_id' => $a->orgId, 'first_name' => $first, 'last_name' => $last] + $cols;
        $pdo->prepare('INSERT INTO contacts (' . implode(', ', array_keys($cols)) . ', created_at) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ' . $createdSql . ')')
            ->execute(array_values($cols));

        return $h->c[$first] = (int) $pdo->lastInsertId();
    };
    $add('LiteAud', 'Ana', ['email' => 'ana@aud.test', 'phone' => '09151110001', 'status' => 'active', 'gender' => 'female', 'city' => 'AudCityA', 'province' => 'AudProvA',
        'country' => 'AudLand', 'source' => 'AudWeb', 'position' => 'AudManager', 'date_of_birth' => '1990-02-28', 'category_id' => $h->group, 'company_id' => $company], 'NOW() - INTERVAL 1 DAY');
    $add('LiteAud', 'Bob', ['phone' => '09151110002', 'status' => 'inactive', 'gender' => 'male', 'city' => 'AudCityB', 'province' => 'AudProvB',
        'country' => 'AudOther', 'source' => 'AudFair', 'position' => 'AudClerk', 'date_of_birth' => '1988-02-29'], 'NOW() - INTERVAL 20 DAY');
    $add('LiteAud', 'Cam', ['email' => 'cam@aud.test', 'status' => 'active'], 'NOW() - INTERVAL 60 DAY');
    $pdo->prepare("INSERT INTO taggables (tag_id, taggable_type, taggable_id) VALUES (?, 'contact', ?)")->execute([$h->tag, $h->c['Ana']]);
    $interest = $pdo->prepare('INSERT INTO contact_interests (organization_id, contact_id, interest) VALUES (?, ?, ?)');
    $interest->execute([$a->orgId, $h->c['Ana'], 'AudCoffee']);
    $interest->execute([$a->orgId, $h->c['Bob'], 'AudTea']);
    $sup = $pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, contact_id, reason, source) VALUES (?, ?, ?, ?, 'manual', 'user')");
    $sup->execute([$a->orgId, 'sms', '989151110002', $h->c['Bob']]);     // linked to the contact
    $sup->execute([$a->orgId, 'email', 'cam@aud.test', null]);           // by address only

    // Date boundaries: created_at is a stored UTC instant; a calendar date means the ORGANIZATION's local day.
    $tz = \App\Infrastructure\Time\OrganizationTimezone::shared();
    $local = fn (string $wall) => $pdo->quote($tz->toStorage($wall, $a->orgId));
    $h->today = $tz->today($a->orgId);
    $add('LiteAudT', 'Midnight', [], $local($h->today . ' 00:00:00'));
    $add('LiteAudT', 'LastSecond', [], $local(date('Y-m-d', strtotime($h->today . ' -1 day')) . ' 23:59:59'));
    $add('LiteAudT', 'Inside7', [], 'NOW() - INTERVAL 7 DAY + INTERVAL 2 MINUTE');
    $add('LiteAudT', 'Outside7', [], 'NOW() - INTERVAL 7 DAY - INTERVAL 2 MINUTE');
    $add('LiteAudT', 'JanEnd', [], $local('2026-01-31 23:59:59'));
    $add('LiteAudT', 'FebStart', [], $local('2026-02-01 00:00:00'));
    $add('LiteAudT', 'NewYear', [], $local('2027-01-01 00:00:00'));

    $pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')->execute([$a->orgId, 'lite_aud_no_contacts', 'lite_aud_no_contacts']);
    $roleId = (int) $pdo->lastInsertId();
    foreach (['marketing.view', 'marketing.create', 'marketing.manage_segments'] as $p) {
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$roleId, $p]);
    }
    $h->noContacts = array_merge($a->member, ['role_id' => $roleId]);

    $h->segments = $c->make(SegmentService::class);
    $h->campaigns = $c->make(CampaignService::class);
    $h->worker = $c->make(QueueWorker::class);
    $h->rules = new SegmentRules($c->make(SegmentConditionRegistry::class));
    $h->ready = true;
};

/** Fixture names (of one last name) matching rules. */
$match = function (string $last, array $conditions, string $groupMatch = 'all') use ($h): array {
    $rules = ['match' => 'all', 'groups' => [
        ['match' => 'all', 'conditions' => [['field' => 'last_name', 'op' => 'eq', 'value' => $last]]],
        ['match' => $groupMatch, 'conditions' => $conditions],
    ]];
    $checked = $h->segments->checkRules($h->a->admin, $rules);
    if ($checked['rules'] === null) {
        throw new LiteAssertionFailed('rules rejected: ' . implode(', ', $checked['errors']) . ' ' . json_encode($conditions));
    }
    $names = array_column($h->segments->preview($h->a->admin, null, $checked['rules'])['rows'], 'first_name');
    sort($names);

    return $names;
};
$f = fn (string $field, string $op, mixed $value = null) => ['field' => $field, 'op' => $op, 'value' => $value];

$names = function (int $segmentId) use ($h): array {
    $rows = array_column($h->segments->preview($h->a->admin, $segmentId, null)['rows'], 'first_name');
    sort($rows);

    return $rows;
};

$rebuild = function (object $service, array $overrides): object {
    $class = new \ReflectionClass($service);
    $args = [];
    foreach ($class->getConstructor()->getParameters() as $param) {
        $name = $param->getName();
        $args[] = array_key_exists($name, $overrides) ? $overrides[$name] : (new \ReflectionProperty($service, $name))->getValue($service);
    }

    return $class->newInstanceArgs($args);
};

$smsCampaign = function (string $name, int $segmentId) use ($h): int {
    $h->a->as($h->a->admin);
    $r = $h->a->request('POST', '/marketing/campaigns', [], ['name' => $name, 'channel' => 'sms', 'message_class' => 'marketing', 'body_text' => 'Hi {{first_name}}', 'audience_type' => 'segment', 'segment_id' => (string) $segmentId]);
    if ($r['status'] !== 302 || preg_match('#^/marketing/campaigns/(\d+)$#', (string) $r['location'], $m) !== 1) {
        throw new LiteAssertionFailed('campaign not created: HTTP ' . $r['status']);
    }

    return (int) $m[1];
};

return [
    'filters: every built-in field and operator selects exactly the right contacts' => function () use ($setup, $match, $f) {
        $setup();
        $cases = [
            [[$f('first_name', 'eq', 'Ana')], ['Ana']],
            [[$f('first_name', 'contains', 'o')], ['Bob']],
            [[$f('email', 'contains', '@aud.test')], ['Ana', 'Cam']],
            [[$f('email', 'empty')], ['Bob']],
            [[$f('phone', 'starts_with', '0915111000')], ['Ana', 'Bob']],
            [[$f('phone', 'empty')], ['Cam']],
            [[$f('status', 'eq', 'inactive')], ['Bob']],
            [[$f('status', 'neq', 'active')], ['Bob']],
            [[$f('gender', 'eq', 'female')], ['Ana']],
            [[$f('gender', 'empty')], ['Cam']],
            [[$f('city', 'eq', 'AudCityA')], ['Ana']],
            [[$f('city', 'not_contains', 'AudCityA')], ['Bob', 'Cam']],
            [[$f('city', 'empty')], ['Cam']],
            [[$f('city', 'contains', '%')], []],                       // LIKE wildcard is literal
            [[$f('province', 'eq', 'AudProvB')], ['Bob']],
            [[$f('country', 'neq', 'AudLand')], ['Bob', 'Cam']],
            [[$f('source', 'contains', 'Fair')], ['Bob']],
            [[$f('position', 'eq', 'AudManager')], ['Ana']],
            [[$f('created_at', 'last_days', '7')], ['Ana']],
            [[$f('created_at', 'last_days', '30')], ['Ana', 'Bob']],
            [[$f('created_at', 'older_than_days', '30')], ['Cam']],
            [[$f('date_of_birth', 'on', '1990-02-28')], ['Ana']],
            [[$f('date_of_birth', 'before', '1989-01-01')], ['Bob']],
            [[$f('date_of_birth', 'empty')], ['Cam']],
            [[$f('birth_month', 'eq', '2')], ['Ana', 'Bob']],          // incl. a 29 February birthday
        ];
        foreach ($cases as [$conditions, $expected]) {
            LiteAssert::same($expected, $match('LiteAud', $conditions), json_encode($conditions));
        }
    },

    'filters: relations — group, tag, company, interest, SMS / email opt-out' => function () use ($setup, $h, $match, $f) {
        $setup();
        $cases = [
            [[$f('group', 'in', (string) $h->group)], ['Ana']],
            [[$f('group', 'not_in', (string) $h->group)], ['Bob', 'Cam']],
            [[$f('group', 'empty')], ['Bob', 'Cam']],
            [[$f('tag', 'has', (string) $h->tag)], ['Ana']],
            [[$f('tag', 'not_has', (string) $h->tag)], ['Bob', 'Cam']],
            [[$f('company', 'not_empty')], ['Ana']],
            [[$f('company', 'empty')], ['Bob', 'Cam']],
            [[$f('interest', 'has', 'AudTea')], ['Bob']],
            [[$f('interest', 'contains', 'Coff')], ['Ana']],
            [[$f('opted_out', 'is', 'sms')], ['Bob']],
            [[$f('opted_out', 'is_not', 'sms')], ['Ana', 'Cam']],
            [[$f('opted_out', 'is', 'email')], ['Cam']],                // matched by address, no contact link
            [[$f('opted_out', 'is_not', 'email')], ['Ana', 'Bob']],
            // nested OR inside AND
            [[$f('city', 'eq', 'AudCityA'), $f('opted_out', 'is', 'email')], ['Ana', 'Cam'], 'any'],
        ];
        foreach ($cases as $case) {
            LiteAssert::same($case[1], $match('LiteAud', $case[0], $case[2] ?? 'all'), json_encode($case[0]));
        }
    },

    'dates: today / midnight / last second of a day / month end / year change in the organization\'s time zone' => function () use ($setup, $h, $match, $f) {
        $setup();
        $today = $h->today;
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $all = ['FebStart', 'Inside7', 'JanEnd', 'LastSecond', 'Midnight', 'NewYear', 'Outside7'];
        $expect = [
            [[$f('created_at', 'on', $today)], ['Midnight']],
            [[$f('created_at', 'on', $yesterday)], ['LastSecond']],
            [[$f('created_at', 'before', $today)], ['FebStart', 'Inside7', 'JanEnd', 'LastSecond', 'Outside7']],
            [[$f('created_at', 'after', $yesterday)], ['Midnight', 'NewYear']],
            [[$f('created_at', 'last_days', '7')], ['Inside7', 'LastSecond', 'Midnight', 'NewYear']],
            [[$f('created_at', 'older_than_days', '7')], ['FebStart', 'JanEnd', 'Outside7']],
            [[$f('created_at', 'on', '2026-01-31')], ['JanEnd']],
            [[$f('created_at', 'on', '2026-02-01')], ['FebStart']],
            [[$f('created_at', 'after', '2026-12-31')], ['NewYear']],
        ];
        $original = date_default_timezone_get();
        try {
            // The result must not depend on PHP's time zone (incl. +14h and a DST zone).
            foreach (['UTC', 'Pacific/Kiritimati', 'America/New_York', 'Asia/Tehran'] as $tz) {
                date_default_timezone_set($tz);
                foreach ($expect as [$conditions, $expected]) {
                    LiteAssert::same($expected, $match('LiteAudT', $conditions), $tz . ' ' . json_encode($conditions));
                }
                LiteAssert::same($all, $match('LiteAudT', [$f('created_at', 'after', '2000-01-01')]));
            }
            date_default_timezone_set('America/New_York');
            LiteAssert::same(['FebStart'], $match('LiteAudT', [$f('created_at', 'on', '2026-02-01')]));
            LiteAssert::same([], $match('LiteAudT', [$f('created_at', 'on', '2026-03-08')]), 'a DST day is still one calendar day');
        } finally {
            date_default_timezone_set($original);
        }
    },

    'dates: invalid dates and day counts are rejected' => function () use ($setup, $h, $f) {
        $setup();
        $bad = [
            $f('created_at', 'on', '2027-02-29'), $f('created_at', 'on', '2026-13-01'), $f('created_at', 'on', '2026-1-1'),
            $f('created_at', 'on', "2026-01-01' OR '1'='1"), $f('created_at', 'last_days', '0'), $f('created_at', 'last_days', '3651'),
            $f('created_at', 'last_days', '7 OR 1=1'), $f('created_at', 'last_days', '-1'), $f('date_of_birth', 'last_days', '7'),
            $f('birth_month', 'eq', '13'), $f('status', 'eq', 'deleted'),
        ];
        foreach ($bad as $condition) {
            LiteAssert::same(null, $h->rules->validate(['groups' => [['conditions' => [$condition]]]])['rules'], json_encode($condition));
        }
        LiteAssert::true($h->rules->validate(['groups' => [['conditions' => [$f('created_at', 'on', '2028-02-29')]]]])['rules'] !== null, 'leap day accepted');
    },

    'tampered stored rules never widen the audience and never inject SQL' => function () use ($setup, $h, $names) {
        $setup();
        $a = $h->a;
        $id = $h->segments->create($a->admin, ['type' => 'dynamic', 'name' => 'LiteAud tamper', 'rules_json' => json_encode(['groups' => [['conditions' => [['field' => 'city', 'op' => 'eq', 'value' => 'AudCityA']]]]])]);
        LiteAssert::same(['Ana'], $names($id));
        $scope = ['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteAud'];
        $variants = [
            ['groups' => [['conditions' => [$scope, ['field' => 'city` = city OR 1=1 #', 'op' => 'eq', 'value' => 'x']]]]],
            ['groups' => [['conditions' => [$scope, ['field' => 'city', 'op' => 'eq OR 1=1', 'value' => 'x']]]]],
            ['groups' => [['conditions' => [$scope, ['field' => 'city', 'op' => 'eq', 'value' => ['AudCityA', 'AudCityB']]]]]],
            ['groups' => [['conditions' => [$scope, ['field' => 'city', 'op' => 'eq', 'value' => "x' OR '1'='1"]]]]],
            ['groups' => [['conditions' => [$scope, ['field' => 'opted_out', 'op' => 'is_not', 'value' => 'xyz']]]]],
            ['groups' => [['conditions' => [$scope, ['field' => 'opted_out', 'op' => 'is_not', 'value' => '1 OR 1=1']]]]],
            ['groups' => [['conditions' => [$scope, ['field' => 'status', 'op' => 'neq', 'value' => 'nope']]]]],
            ['match' => 'any', 'groups' => [['conditions' => [$scope]], ['conditions' => [['field' => 'nope', 'op' => 'eq', 'value' => 'x']]]]],
            ['groups' => [['conditions' => [['groups' => [['conditions' => [$scope]]]]]]]],
            'not json at all',
        ];
        foreach ($variants as $variant) {
            $a->pdo->prepare('UPDATE marketing_segments SET rules = ? WHERE id = ?')->execute([is_string($variant) ? $variant : json_encode($variant), $id]);
            LiteAssert::same(0, $h->segments->preview($a->admin, $id, null)['total'], 'fails closed: ' . (is_string($variant) ? $variant : json_encode($variant)));
        }
        // A REMOVED group/tag id still compiles safely (bound, organization-pinned): "in" matches nobody, "not in" everybody.
        $a->pdo->prepare('UPDATE marketing_segments SET rules = ? WHERE id = ?')->execute([json_encode(['groups' => [['conditions' => [$scope, ['field' => 'group', 'op' => 'in', 'value' => '999999999']]]]]), $id]);
        LiteAssert::same([], $names($id));
        $a->pdo->prepare('UPDATE marketing_segments SET rules = ? WHERE id = ?')->execute([json_encode(['groups' => [['conditions' => [$scope, ['field' => 'group', 'op' => 'not_in', 'value' => '999999999']]]]]), $id]);
        LiteAssert::same(['Ana', 'Bob', 'Cam'], $names($id));
    },

    'snapshot: a large dynamic segment is resolved as of approval — later contacts and the passing of time do not change it' => function () use ($setup, $h, $rebuild, $smsCampaign, $names) {
        $setup();
        $a = $h->a;
        $pdo = $a->pdo;
        $insert = function (string $first, string $createdSql) use ($a, $pdo, $h): int {
            $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, phone, status, created_at) VALUES (?, ?, 'LiteAudS', ?, 'active', {$createdSql})")
                ->execute([$a->orgId, $first, '0915222' . str_pad((string) (count($h->c) + 1), 4, '0', STR_PAD_LEFT)]);

            return $h->c[$first] = (int) $pdo->lastInsertId();
        };
        $insert('Recent', 'NOW() - INTERVAL 1 DAY');
        $insert('Month', 'NOW() - INTERVAL 33 DAY');
        $rules = ['groups' => [['conditions' => [['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteAudS'], ['field' => 'created_at', 'op' => 'last_days', 'value' => '7']]]]];
        $segment = $h->segments->create($a->admin, ['type' => 'dynamic', 'name' => 'LiteAud snap', 'rules_json' => json_encode($rules)]);
        $service = $rebuild($h->campaigns, ['syncAudienceLimit' => 0]);
        $campaign = $smsCampaign('LiteAud snap', $segment);
        $review = $service->review($a->admin, $service->find($a->admin, $campaign));
        LiteAssert::true($review['large']);
        $service->approveAndQueue($a->admin, $campaign, $review['confirm_count'], false);

        $snapshot = json_decode((string) $pdo->query("SELECT audience_snapshot FROM marketing_campaigns WHERE id = {$campaign}")->fetchColumn(), true);
        LiteAssert::same((int) $pdo->query('SELECT MAX(id) FROM contacts WHERE organization_id = ' . $a->orgId)->fetchColumn(), (int) $snapshot['max_contact_id']);
        LiteAssert::same(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $snapshot['as_of']));

        // After approval: a new matching contact appears, and (simulated) 30 days pass before the worker runs.
        $insert('Later', 'NOW()');
        $snapshot['as_of'] = $pdo->query('SELECT NOW() - INTERVAL 30 DAY')->fetchColumn();
        $pdo->prepare('UPDATE marketing_campaigns SET audience_snapshot = ? WHERE id = ?')->execute([json_encode($snapshot), $campaign]);
        LiteAssert::same(['Later', 'Recent'], $names($segment), 'the live segment moved on');

        $h->worker->processCampaign($campaign, 50, microtime(true) + 10);
        $ids = array_map('intval', $pdo->query("SELECT contact_id FROM marketing_messages WHERE campaign_id = {$campaign} ORDER BY contact_id")->fetchAll(\PDO::FETCH_COLUMN));
        LiteAssert::same([$h->c['Recent'], $h->c['Month']], $ids, '"last 7 days" as of the frozen time; the later contact is excluded');

        // A snapshot stored before this fix (no as_of / max_contact_id) still resolves, on the live clock.
        [$sql] = (new SegmentAudience($a->container()->make(\Modules\Marketing\SegmentRepository::class), $h->rules))->predicate($segment, ['segment_id' => $segment, 'type' => 'dynamic', 'rules' => $rules]);
        LiteAssert::contains('NOW()', $sql);
        LiteAssert::notContains('c.id <=', $sql);
    },

    'privacy: personal values in rules are hidden from users without contacts.view, who cannot open the edit form either' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $id = $h->segments->create($a->admin, ['type' => 'dynamic', 'name' => 'LiteAud private', 'rules_json' => json_encode(['groups' => [['conditions' => [
            ['field' => 'email', 'op' => 'eq', 'value' => 'aud-secret@aud.test'], ['field' => 'city', 'op' => 'eq', 'value' => 'AudCityA'],
        ]]]])]);

        $a->as($a->admin);
        LiteAssert::contains('aud-secret@aud.test', $a->request('GET', "/marketing/segments/{$id}")['body'], 'visible with contacts.view');

        $a->as($h->noContacts);
        $page = $a->request('GET', "/marketing/segments/{$id}")['body'];
        LiteAssert::notContains('aud-secret', $page);
        LiteAssert::contains('•••', $page);
        LiteAssert::contains('AudCityA', $page, 'non-personal values still shown');
        LiteAssert::notContains("/marketing/segments/{$id}/edit", $page);
        LiteAssert::contains("/marketing/segments/{$id}/delete", $page, 'still allowed to delete');
        LiteAssert::same('forbidden', $a->outcome('GET', "/marketing/segments/{$id}/edit"));

        // A segment without personal-field rules stays editable for the same user.
        $plain = $h->segments->create($a->admin, ['type' => 'dynamic', 'name' => 'LiteAud plain', 'rules_json' => json_encode(['groups' => [['conditions' => [['field' => 'city', 'op' => 'eq', 'value' => 'AudCityA']]]]])]);
        LiteAssert::same('ok', $a->outcome('GET', "/marketing/segments/{$plain}/edit"));
        LiteAssert::contains("/marketing/segments/{$plain}/edit", $a->request('GET', "/marketing/segments/{$plain}")['body']);
        $a->as($a->admin);
    },

    'dashboard: "queue stalled" and the 30-day stats use the database clock' => function () use ($setup, $h, $smsCampaign) {
        $setup();
        $a = $h->a;
        $pdo = $a->pdo;
        $segment = $h->segments->create($a->admin, ['type' => 'dynamic', 'name' => 'LiteAud dash', 'rules_json' => json_encode(['groups' => [['conditions' => [['field' => 'city', 'op' => 'eq', 'value' => 'AudCityA']]]]])]);
        $campaign = $smsCampaign('LiteAud dash', $segment);
        $pdo->prepare("UPDATE marketing_messages SET status = 'cancelled' WHERE organization_id = ? AND status = 'pending'")->execute([$a->orgId]);
        // Built like MarketingModule does (it is not registered in the container).
        $class = new \ReflectionClass(InsightsService::class);
        $insights = $class->newInstanceArgs(array_map(fn (\ReflectionParameter $p) => $a->container()->make((string) $p->getType()), $class->getConstructor()->getParameters()));
        $before = $insights->dashboard($a->admin)['stats']['sms']['sent'] ?? 0;

        $msg = function (string $address, string $status, string $age) use ($pdo, $a, $campaign): void {
            $pdo->prepare("INSERT INTO marketing_messages (organization_id, campaign_id, channel, address, status, created_at) VALUES (?, ?, 'sms', ?, ?, NOW() - INTERVAL {$age})")
                ->execute([$a->orgId, $campaign, $address, $status]);
        };
        $msg('989150000001', 'pending', '5 MINUTE');
        LiteAssert::false($insights->dashboard($a->admin)['queue_stalled'], '5 minutes old: not stalled');
        $msg('989150000002', 'pending', '11 MINUTE');
        LiteAssert::true($insights->dashboard($a->admin)['queue_stalled'], '11 minutes old: stalled (whatever PHP\'s time zone)');

        $msg('989150000003', 'sent', '29 DAY');
        $msg('989150000004', 'sent', '31 DAY');
        LiteAssert::same($before + 1, $insights->dashboard($a->admin)['stats']['sms']['sent'], 'only the message inside 30 days counts');
    },

    'cleanup: roll back everything' => function () use ($h) {
        if (isset($h->a)) {
            $h->a->rollBack();
        }
        LiteAssert::true(true);
    },
];
