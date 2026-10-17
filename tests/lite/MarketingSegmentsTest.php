<?php

declare(strict_types=1);

/**
 * Marketing Phase 2 — segments & audiences. crm_local only, one
 * transaction, rolled back. SMS goes to FakeSmsSender (no real message).
 */

use App\Application\Services\SmsService;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use Modules\Marketing\Segments\SegmentConditionRegistry;
use Modules\Marketing\Segments\SegmentRules;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\SegmentService;
use Tests\Lite\Fixtures\FakeSmsSender;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Fixtures/MarketingFakes.php';

$h = new \stdClass();
$h->ready = false;
$h->reason = '';

$rebuild = function (object $service, array $overrides): object {
    $class = new \ReflectionClass($service);
    $args = [];
    foreach ($class->getConstructor()->getParameters() as $param) {
        $name = $param->getName();
        $args[] = array_key_exists($name, $overrides) ? $overrides[$name] : (new \ReflectionProperty($service, $name))->getValue($service);
    }

    return $class->newInstanceArgs($args);
};

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT 1 FROM marketing_segment_contacts LIMIT 1');
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

    // Fixtures: a group, a tag, and contacts covering every rule.
    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteSeg G1']);
    $h->group = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tags (organization_id, name) VALUES (?, 'LiteSeg T1')")->execute([$a->orgId]);
    $h->tag = (int) $pdo->lastInsertId();
    $city = 'LiteSegTabriz';
    $h->city = $city;
    $insert = $pdo->prepare('INSERT INTO contacts (organization_id, first_name, last_name, phone, email, city, status, date_of_birth, category_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $h->c = [];
    foreach ([
        'Ava' => ['09141110001', 'ava@example.test', $city, 'active', '1990-05-10', $h->group, '-2 days'],
        'Ben' => ['09141110002', null, $city, 'inactive', '1985-05-20', null, '-40 days'],
        'Cyrus' => ['09141110003', null, 'LiteSegShiraz', 'active', null, null, '-1 day'],
        'Dara' => ['0914 111 0001', null, 'LiteSegShiraz', 'active', null, $h->group, '-3 days'],
        'Eli' => [null, 'eli@example.test', $city, 'active', null, null, '-5 days'],
        'Fay' => ['09141110006', null, 'LiteSegYazd', 'active', null, null, '-6 days'],
    ] as $name => [$phone, $email, $cityName, $status, $dob, $group, $created]) {
        $insert->execute([$a->orgId, $name, 'LiteSeg', $phone, $email, $cityName, $status, $dob, $group, date('Y-m-d H:i:s', strtotime($created))]);
        $h->c[$name] = (int) $pdo->lastInsertId();
    }
    foreach (['Ava', 'Cyrus'] as $name) {
        $pdo->prepare("INSERT INTO taggables (tag_id, taggable_type, taggable_id) VALUES (?, 'contact', ?)")->execute([$h->tag, $h->c[$name]]);
    }
    $h->foreignContact = null;
    if ($a->otherOrgId !== null) {
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, city, status) VALUES (?, 'Zed', '09141119999', ?, 'active')")->execute([$a->otherOrgId, $city]);
        $h->foreignContact = (int) $pdo->lastInsertId();
    }

    // Roles: segments manager WITHOUT contacts.view; plain marketing member (no manage_segments).
    $role = function (string $key, array $perms) use ($a, $pdo): array {
        $pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')->execute([$a->orgId, $key, $key]);
        $id = (int) $pdo->lastInsertId();
        foreach ($perms as $p) {
            $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$id, $p]);
        }

        return array_merge($a->member, ['role_id' => $id]);
    };
    $h->noContacts = $role('lite_seg_no_contacts', ['marketing.view', 'marketing.create', 'marketing.manage_segments']);
    $h->plain = $role('lite_seg_plain', ['marketing.view', 'marketing.create', 'contacts.view']);

    $h->segments = $c->make(SegmentService::class);
    $h->campaigns = $c->make(CampaignService::class);
    $h->worker = $c->make(QueueWorker::class);
    $h->rules = new SegmentRules($c->make(SegmentConditionRegistry::class));
    $h->ready = true;
};

/** Rules helper: one group of conditions. */
$rules = fn (array $conditions, string $match = 'all', string $top = 'all', array $more = []) => [
    'match' => $top,
    'groups' => array_merge([['match' => $match, 'conditions' => $conditions]], $more),
];
$cityIs = fn (string $city) => ['field' => 'city', 'op' => 'eq', 'value' => $city];

$dynamic = function (string $name, array $rulesTree, ?array $user = null) use ($h): int {
    return $h->segments->create($user ?? $h->a->admin, ['type' => 'dynamic', 'name' => $name, 'rules_json' => json_encode($rulesTree)]);
};

/** Names of matching contacts (fixtures only). */
$names = function (int $segmentId) use ($h): array {
    $rows = $h->segments->preview($h->a->admin, $segmentId, null)['rows'];
    $names = array_values(array_filter(array_map(fn ($r) => $r['first_name'], $rows), fn ($n) => in_array($n, ['Ava', 'Ben', 'Cyrus', 'Dara', 'Eli', 'Fay'], true)));
    sort($names);

    return $names;
};

$smsCampaign = function (string $name, int $segmentId, array $extra = []) use ($h): int {
    $h->a->as($h->a->admin);
    $r = $h->a->request('POST', '/marketing/campaigns', [], $extra + ['name' => $name, 'channel' => 'sms', 'message_class' => 'marketing', 'body_text' => 'Hi {{first_name}}', 'audience_type' => 'segment', 'segment_id' => (string) $segmentId]);
    if ($r['status'] !== 302 || preg_match('#^/marketing/campaigns/(\d+)$#', (string) $r['location'], $m) !== 1) {
        throw new LiteAssertionFailed('campaign not created: HTTP ' . $r['status']);
    }

    return (int) $m[1];
};

$confirm = fn (int $id) => $h->campaigns->review($h->a->admin, $h->campaigns->find($h->a->admin, $id))['confirm_count'];

return [
    // --- Rules engine ----------------------------------------------------------

    'rules: unknown fields, operators and SQL fragments are rejected by the allowlist' => function () use ($setup, $h, $cityIs) {
        $setup();
        $bad = [
            ['field' => 'contacts.organization_id', 'op' => 'eq', 'value' => '1'],
            ['field' => 'city; DROP TABLE contacts', 'op' => 'eq', 'value' => 'x'],
            ['field' => 'city', 'op' => '= 1 OR 1=1 --', 'value' => 'x'],
            ['field' => 'city', 'op' => 'eq', 'value' => ''],
            ['field' => 'created_at', 'op' => 'before', 'value' => "2020-01-01' OR '1'='1"],
            ['field' => 'group', 'op' => 'in', 'value' => '999999999'],
            ['field' => 'birth_month', 'op' => 'eq', 'value' => '13'],
        ];
        foreach ($bad as $condition) {
            $result = $h->rules->validate(['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [$condition]]]]);
            LiteAssert::same(null, $result['rules'], 'rejected: ' . json_encode($condition));
        }
        LiteAssert::same(null, $h->rules->validate('not json')['rules']);
        LiteAssert::same(null, $h->rules->validate(['match' => 'all', 'groups' => []])['rules'], 'no conditions');
        LiteAssert::true($h->rules->validate(['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [$cityIs('x')]]]])['rules'] !== null);

        // A tampered stored rule never reaches SQL: unknown field → matches nothing.
        $params = new \Modules\Marketing\Segments\SqlParams($h->a->orgId);
        LiteAssert::same('1 = 0', $h->rules->compile(['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [['field' => 'id) OR (1=1', 'op' => 'eq', 'value' => 1]]]]], $params));
    },

    'rules: a malicious VALUE is only ever a bound parameter' => function () use ($setup, $h, $dynamic, $rules) {
        $setup();
        $before = (int) $h->a->pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
        $id = $dynamic('LiteSeg injection', $rules([['field' => 'city', 'op' => 'eq', 'value' => "x' OR '1'='1"], ['field' => 'first_name', 'op' => 'contains', 'value' => "%' UNION SELECT 1 --"]], 'any'));
        LiteAssert::same(0, $h->segments->preview($h->a->admin, $id, null)['total']);
        LiteAssert::same($before, (int) $h->a->pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn());
    },

    'dynamic matching: AND, OR, nested groups, dates, tags, groups, birthday month' => function () use ($setup, $h, $dynamic, $rules, $cityIs, $names) {
        $setup();
        $id = $dynamic('LiteSeg AND', $rules([$cityIs($h->city), ['field' => 'status', 'op' => 'eq', 'value' => 'active']]));
        LiteAssert::same(['Ava', 'Eli'], $names($id), 'city AND active');

        $id = $dynamic('LiteSeg OR', $rules([$cityIs('LiteSegYazd'), $cityIs('LiteSegShiraz')], 'any'));
        LiteAssert::same(['Cyrus', 'Dara', 'Fay'], $names($id), 'city OR city');

        // (city = Tabriz AND inactive) OR (has tag T1)
        $nested = $rules([$cityIs($h->city), ['field' => 'status', 'op' => 'eq', 'value' => 'inactive']], 'all', 'any', [
            ['match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'has', 'value' => (string) $h->tag]]],
        ]);
        LiteAssert::same(['Ava', 'Ben', 'Cyrus'], $names($dynamic('LiteSeg nested', $nested)));

        LiteAssert::same(['Ava', 'Dara'], $names($dynamic('LiteSeg group', $rules([['field' => 'group', 'op' => 'in', 'value' => (string) $h->group]]))));
        LiteAssert::same(['Ava', 'Ben'], $names($dynamic('LiteSeg month', $rules([['field' => 'birth_month', 'op' => 'eq', 'value' => '5']]))));
        $recent = $names($dynamic('LiteSeg recent', $rules([['field' => 'created_at', 'op' => 'last_days', 'value' => '4'], ['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteSeg']])));
        LiteAssert::same(['Ava', 'Cyrus', 'Dara'], $recent, 'created in the last 4 days');
        LiteAssert::same(['Ava', 'Ben', 'Eli'], $names($dynamic('LiteSeg no-phone-or-email', $rules([$cityIs($h->city), ['field' => 'email', 'op' => 'not_empty']], 'any', 'all', [['match' => 'all', 'conditions' => [['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteSeg']]]]))) === ['Ava', 'Ben', 'Eli'] ? ['Ava', 'Ben', 'Eli'] : ['mismatch']);
    },

    'dynamic segments recalculate on every use — no refresh needed' => function () use ($setup, $h, $dynamic, $rules, $cityIs, $names) {
        $setup();
        $id = $dynamic('LiteSeg live', $rules([$cityIs('LiteSegYazd')]));
        LiteAssert::same(['Fay'], $names($id));
        $h->a->pdo->exec("UPDATE contacts SET city = 'LiteSegYazd' WHERE id = {$h->c['Cyrus']}");
        LiteAssert::same(['Cyrus', 'Fay'], $names($id));
        $h->a->pdo->exec("UPDATE contacts SET city = 'LiteSegShiraz' WHERE id = {$h->c['Cyrus']}");
    },

    'opt-out filter: "opted out of SMS" / "not opted out" follow the organization\'s suppression list' => function () use ($setup, $h, $dynamic, $rules, $names) {
        $setup();
        $a = $h->a;
        $a->pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, contact_id, reason, source) VALUES (?, 'sms', '09141110003', ?, 'manual', 'user')")->execute([$a->orgId, $h->c['Cyrus']]);
        try {
        $base = ['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteSeg'];
        LiteAssert::same(['Cyrus'], $names($dynamic('LiteSeg opted', $rules([$base, ['field' => 'opted_out', 'op' => 'is', 'value' => 'sms']]))));
        LiteAssert::false(in_array('Cyrus', $names($dynamic('LiteSeg not opted', $rules([$base, ['field' => 'opted_out', 'op' => 'is_not', 'value' => 'sms']]))), true));
        // Matched by address too (a suppression without contact_id), and for email.
        $a->pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source) VALUES (?, 'email', 'eli@example.test', 'unsubscribed', 'link')")->execute([$a->orgId]);
        LiteAssert::same(['Eli'], $names($dynamic('LiteSeg opted email', $rules([$base, ['field' => 'opted_out', 'op' => 'is', 'value' => 'email']]))));
        } finally {
        $a->pdo->exec("DELETE FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND address = 'eli@example.test'");
        $a->pdo->exec("DELETE FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND address = '09141110003'");
        }
    },

    // --- Static segments -------------------------------------------------------

    'static: members added and removed explicitly; other organizations\' contacts are ignored; deleted contacts drop out' => function () use ($setup, $h, $names) {
        $setup();
        $a = $h->a;
        $id = $h->segments->create($a->admin, ['type' => 'static', 'name' => 'LiteSeg static']);
        $add = [$h->c['Ava'], $h->c['Ben'], $h->c['Cyrus']];
        if ($h->foreignContact !== null) {
            $add[] = $h->foreignContact;
        }
        LiteAssert::same(3, $h->segments->addMembers($a->admin, $id, $add), 'foreign contact not added');
        LiteAssert::same(0, $h->segments->addMembers($a->admin, $id, [$h->c['Ava']]), 'no duplicate membership');
        LiteAssert::same(['Ava', 'Ben', 'Cyrus'], $names($id));
        LiteAssert::same(1, $h->segments->removeMembers($a->admin, $id, [$h->c['Ben']]));
        LiteAssert::same(['Ava', 'Cyrus'], $names($id));

        $a->pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, status) VALUES (?, 'Temp', '09141110099', 'active')")->execute([$a->orgId]);
        $temp = (int) $a->pdo->lastInsertId();
        $h->segments->addMembers($a->admin, $id, [$temp]);
        $a->pdo->exec("DELETE FROM contacts WHERE id = {$temp}");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_segment_contacts WHERE contact_id = {$temp}")->fetchColumn(), 'deleted contact removed from the segment');
        $h->staticSegment = $id;
    },

    'static: through the UI — search, add, remove' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $id = $h->staticSegment ?? throw new LiteSkip('depends on the previous test');
        $a->as($a->admin);
        $page = $a->request('GET', "/marketing/segments/{$id}", ['find' => '1', 'q' => 'Fay'])['body'];
        LiteAssert::contains('value="' . $h->c['Fay'] . '"', $page);
        $a->request('POST', "/marketing/segments/{$id}/members", [], ['contact_ids' => [(string) $h->c['Fay']]]);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_segment_contacts WHERE segment_id = {$id} AND contact_id = {$h->c['Fay']}")->fetchColumn());
        $a->request('POST', "/marketing/segments/{$id}/members/remove", [], ['contact_ids' => [(string) $h->c['Fay']]]);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_segment_contacts WHERE segment_id = {$id} AND contact_id = {$h->c['Fay']}")->fetchColumn());
    },

    // --- Permissions, privacy, tenancy, entitlement ----------------------------

    'RBAC: managing segments needs marketing.manage_segments; viewing needs marketing.view' => function () use ($setup, $h, $rules, $cityIs) {
        $setup();
        $a = $h->a;
        $a->as($h->plain);
        LiteAssert::same('ok', $a->outcome('GET', '/marketing/segments'));
        LiteAssert::same('forbidden', $a->outcome('GET', '/marketing/segments/create'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/marketing/segments', [], ['type' => 'dynamic', 'name' => 'x', 'rules_json' => json_encode($rules([$cityIs('x')]))]));
        $a->as($a->noRole);
        LiteAssert::same('forbidden', $a->outcome('GET', '/marketing/segments'));
        LiteAssert::throws(\App\Core\Exceptions\AuthorizationException::class, fn () => $h->segments->create($h->plain, ['type' => 'dynamic', 'name' => 'x']), 'service re-checks');
    },

    'contacts.view: without it — no static segments, no personal-field rules, previews show counts only' => function () use ($setup, $h, $dynamic, $rules, $cityIs) {
        $setup();
        $a = $h->a;
        $u = $h->noContacts;
        LiteAssert::throws(ValidationException::class, fn () => $h->segments->create($u, ['type' => 'static', 'name' => 'x']));
        LiteAssert::throws(ValidationException::class, fn () => $dynamic('x', $rules([['field' => 'email', 'op' => 'contains', 'value' => 'ava@']]), $u), 'personal field refused');

        $id = $dynamic('LiteSeg noview', $rules([$cityIs($h->city)]), $u);
        $preview = $h->segments->preview($u, $id, null);
        LiteAssert::same(3, $preview['total']);
        LiteAssert::same([], $preview['rows']);

        $a->as($u);
        $page = $a->request('GET', "/marketing/segments/{$id}")['body'];
        foreach (['Ava', '09141110001', 'ava@example.test'] as $secret) {
            LiteAssert::notContains($secret, $page);
        }
        $form = $a->request('GET', '/marketing/segments/create', ['type' => 'dynamic'])['body'];
        LiteAssert::notContains('"key":"email"', $form, 'personal fields not offered in the builder');
        LiteAssert::contains('"key":"city"', $form);
        LiteAssert::throws(\App\Core\Exceptions\AuthorizationException::class, fn () => $h->segments->addMembers($u, $h->staticSegment ?? 0, [$h->c['Ava']]));

        // An admin-made segment on email can be USED by this user, but not edited.
        $adminSeg = $dynamic('LiteSeg email', $rules([['field' => 'email', 'op' => 'contains', 'value' => 'example.test']]));
        LiteAssert::throws(ValidationException::class, fn () => $h->segments->update($u, $adminSeg, ['name' => 'x', 'rules_json' => json_encode($rules([$cityIs('x')]))]));
    },

    'organization isolation: another organization\'s segment is invisible and unusable; rules never reach its contacts' => function () use ($setup, $h, $dynamic, $rules, $cityIs) {
        $setup();
        $a = $h->a;
        if ($a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $a->pdo->prepare("INSERT INTO marketing_segments (organization_id, name, type, rules) VALUES (?, 'LiteSeg foreign', 'dynamic', ?)")->execute([$a->otherOrgId, json_encode($rules([$cityIs($h->city)]))]);
        $foreign = (int) $a->pdo->lastInsertId();
        $a->as($a->admin);
        LiteAssert::same('notfound', $a->outcome('GET', "/marketing/segments/{$foreign}"));
        LiteAssert::notContains('LiteSeg foreign', $a->request('GET', '/marketing/segments')['body']);
        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteSeg foreign use', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'segment', 'segment_id' => (string) $foreign]);
        LiteAssert::same(422, $r['status']);

        $own = $dynamic('LiteSeg own tabriz', $rules([$cityIs($h->city)]));
        $rows = $h->segments->preview($a->admin, $own, null)['rows'];
        LiteAssert::false(in_array('Zed', array_column($rows, 'first_name'), true), 'other organization\'s contact never matched');
    },

    'entitlement: without the Marketing module every segment route is 404' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->setOrgEntitled('marketing', false);
        $a->container()->make(ModuleGate::class)->reset();
        try {
            $a->as($a->admin);
            LiteAssert::same('notfound', $a->outcome('GET', '/marketing/segments'));
            LiteAssert::same('notfound', $a->outcome('POST', '/marketing/segments', [], ['type' => 'dynamic', 'name' => 'x']));
        } finally {
            $a->setOrgEntitled('marketing', true);
            $a->container()->make(ModuleGate::class)->reset();
        }
    },

    // --- Campaign integration -------------------------------------------------

    'campaign: a segment audience is re-resolved, deduplicated and opt-outs are skipped' => function () use ($setup, $h, $dynamic, $rules, $smsCampaign, $confirm) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $a->request('POST', '/marketing/suppressions', [], ['channel' => 'sms', 'address' => '09141110006']); // Fay opted out
        // Ava and Dara share a number; Eli has no phone; Ben is inactive (excluded by the rule).
        $id = $dynamic('LiteSeg campaign', $rules([['field' => 'status', 'op' => 'eq', 'value' => 'active'], ['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteSeg']]));
        $campaign = $smsCampaign('LiteSeg c1', $id);
        $review = $h->campaigns->review($a->admin, $h->campaigns->find($a->admin, $campaign))['totals'];
        LiteAssert::same(5, $review['contacts'], 'matching (estimate)');
        LiteAssert::same(2, $review['eligible'], 'Ava/Dara once + Cyrus');
        LiteAssert::same(1, $review['duplicate']);
        LiteAssert::same(1, $review['suppressed']);
        LiteAssert::same(1, $review['missing_address']);

        $page = $a->request('GET', "/marketing/campaigns/{$campaign}")['body'];
        LiteAssert::contains(htmlspecialchars(\App\Infrastructure\Theme\t('marketing.estimate_hint'), ENT_QUOTES), $page, 'estimate is labelled as such');

        $before = count($h->sms->sent);
        LiteAssert::same(302, $a->request('POST', "/marketing/campaigns/{$campaign}/send", [], ['confirmed_count' => '2'])['status']);
        $snapshot = json_decode((string) $a->pdo->query("SELECT audience_snapshot FROM marketing_campaigns WHERE id = {$campaign}")->fetchColumn(), true);
        LiteAssert::same('dynamic', $snapshot['type']);
        $h->worker->processCampaign($campaign, 50, microtime(true) + 10);
        LiteAssert::same(2, count($h->sms->sent) - $before);
        LiteAssert::same(422, $a->request('POST', "/marketing/campaigns/{$campaign}/send", [], ['confirmed_count' => '2'])['status'], 'second approval refused');
        $h->worker->runAll(3);
        LiteAssert::same(2, count($h->sms->sent) - $before, 'no duplicate sends on rerun');
    },

    'campaign: a segment change between preview and approval is caught; approval uses the current data' => function () use ($setup, $h, $dynamic, $rules, $cityIs, $smsCampaign, $confirm) {
        $setup();
        $a = $h->a;
        $id = $dynamic('LiteSeg drift', $rules([$cityIs('LiteSegShiraz'), ['field' => 'status', 'op' => 'eq', 'value' => 'active']]));
        $campaign = $smsCampaign('LiteSeg drift', $id);
        $previewCount = $confirm($campaign); // Cyrus + Dara = 2
        $a->pdo->exec("UPDATE contacts SET city = 'LiteSegShiraz' WHERE id = {$h->c['Fay']}");
        $h->a->pdo->exec("DELETE FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND address = '09141110006'");
        $a->as($a->admin);
        LiteAssert::same(422, $a->request('POST', "/marketing/campaigns/{$campaign}/send", [], ['confirmed_count' => (string) $previewCount])['status'], 'stale count refused');
        LiteAssert::same(302, $a->request('POST', "/marketing/campaigns/{$campaign}/send", [], ['confirmed_count' => (string) $confirm($campaign)])['status']);
        LiteAssert::same($previewCount + 1, (int) $a->pdo->query("SELECT total_recipients FROM marketing_campaigns WHERE id = {$campaign}")->fetchColumn());
        $a->pdo->exec("UPDATE contacts SET city = 'LiteSegYazd' WHERE id = {$h->c['Fay']}");
    },

    'campaign: transactional restrictions from 3.1 still apply to segments' => function () use ($setup, $h, $dynamic, $rules, $cityIs) {
        $setup();
        $a = $h->a;
        $id = $dynamic('LiteSeg tx', $rules([$cityIs($h->city)]));
        $a->as($a->admin);
        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteSeg tx', 'channel' => 'sms', 'message_class' => 'transactional', 'body_text' => 'x', 'audience_type' => 'segment', 'segment_id' => (string) $id]);
        LiteAssert::same(422, $r['status']);
    },

    'campaign: an empty segment cannot be approved' => function () use ($setup, $h, $dynamic, $rules, $cityIs, $smsCampaign) {
        $setup();
        $id = $dynamic('LiteSeg empty', $rules([$cityIs('LiteSegNowhere')]));
        $campaign = $smsCampaign('LiteSeg empty', $id);
        $h->a->as($h->a->admin);
        LiteAssert::same(422, $h->a->request('POST', "/marketing/campaigns/{$campaign}/send", [], ['confirmed_count' => '0'])['status']);
    },

    'large segment: background preparation uses the frozen snapshot and resumes from the cursor' => function () use ($setup, $h, $dynamic, $rules, $smsCampaign, $rebuild) {
        $setup();
        $a = $h->a;
        $service = $rebuild($h->campaigns, ['syncAudienceLimit' => 2]);
        $id = $dynamic('LiteSeg large', $rules([['field' => 'last_name', 'op' => 'eq', 'value' => 'LiteSeg'], ['field' => 'phone', 'op' => 'not_empty']]));
        $campaign = $smsCampaign('LiteSeg large', $id);
        $review = $service->review($a->admin, $service->find($a->admin, $campaign));
        LiteAssert::true($review['large']);
        $service->approveAndQueue($a->admin, $campaign, $review['confirm_count'], false);
        $row = $a->pdo->query("SELECT status, prepare_cursor, audience_snapshot FROM marketing_campaigns WHERE id = {$campaign}")->fetch();
        LiteAssert::same('preparing', $row['status']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$campaign}")->fetchColumn());

        // Editing the segment after approval must not change this campaign.
        $h->segments->update($a->admin, $id, ['name' => 'LiteSeg large', 'rules_json' => json_encode($rules([['field' => 'city', 'op' => 'eq', 'value' => 'LiteSegNowhere']]))]);
        // Resume after Cyrus (as after a timeout): Dara and Fay remain (+ Ava/Ben before the cursor are skipped).
        $a->pdo->exec("UPDATE marketing_campaigns SET prepare_cursor = {$h->c['Cyrus']} WHERE id = {$campaign}");
        $h->worker->processCampaign($campaign, 50, microtime(true) + 10);
        $addresses = $a->pdo->query("SELECT contact_id FROM marketing_messages WHERE campaign_id = {$campaign} ORDER BY contact_id")->fetchAll(\PDO::FETCH_COLUMN);
        LiteAssert::same([$h->c['Dara'], $h->c['Fay']], array_map('intval', $addresses), 'snapshot rules used, from the cursor');
        LiteAssert::true(in_array($a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$campaign}")->fetchColumn(), ['sending', 'completed'], true));
    },

    'deleted segment: hidden and unusable for new campaigns; campaigns that already use it keep working; history kept' => function () use ($setup, $h, $smsCampaign, $rebuild) {
        $setup();
        $a = $h->a;
        $id = $h->segments->create($a->admin, ['type' => 'static', 'name' => 'LiteSeg to delete']);
        $h->segments->addMembers($a->admin, $id, [$h->c['Cyrus'], $h->c['Fay']]);
        $draft = $smsCampaign('LiteSeg draft on deleted', $id);
        $running = $smsCampaign('LiteSeg running on deleted', $id);
        $service = $rebuild($h->campaigns, ['syncAudienceLimit' => 1]);
        $service->approveAndQueue($a->admin, $running, 2, false); // background, static snapshot

        $a->as($a->admin);
        $a->request('POST', "/marketing/segments/{$id}/delete");
        LiteAssert::true($a->pdo->query("SELECT deleted_at FROM marketing_segments WHERE id = {$id}")->fetchColumn() !== null, 'soft-deleted');
        LiteAssert::same('notfound', $a->outcome('GET', "/marketing/segments/{$id}"));
        LiteAssert::notContains('LiteSeg to delete', $a->request('GET', '/marketing/segments')['body']);
        LiteAssert::same(422, $a->request('POST', "/marketing/campaigns/{$draft}/send", [], ['confirmed_count' => '2'])['status'], 'draft on a deleted segment refused');

        $before = count($h->sms->sent);
        $h->worker->processCampaign($running, 50, microtime(true) + 10);
        LiteAssert::same(2, count($h->sms->sent) - $before, 'already-approved campaign still sends to its members');
        LiteAssert::same('200', (string) $a->request('GET', "/marketing/campaigns/{$running}")['status'], 'history page intact');
    },

    'UI: list, builder form and segment page render; campaign form offers saved segments' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        LiteAssert::contains('href="/marketing/segments/create?type=dynamic"', $a->request('GET', '/marketing/segments')['body']);
        $form = $a->request('GET', '/marketing/segments/create', ['type' => 'dynamic'])['body'];
        LiteAssert::contains('id="segment-builder-data"', $form);
        LiteAssert::contains('data-segment-rules', $form);
        $campaignForm = $a->request('GET', '/marketing/campaigns/create', ['channel' => 'sms', 'segment' => (string) ($h->staticSegment ?? 0)])['body'];
        LiteAssert::contains('value="segment" checked', $campaignForm);
        LiteAssert::contains('name="segment_id"', $campaignForm);

        // Unsaved-rules preview button
        $preview = $a->request('POST', '/marketing/segments', [], ['type' => 'dynamic', 'name' => 'x', 'action' => 'preview', 'rules_json' => json_encode(['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [['field' => 'city', 'op' => 'eq', 'value' => $h->city]]]]])]);
        LiteAssert::same(200, $preview['status']);
        LiteAssert::contains('Ava', $preview['body']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_segments WHERE name = 'x'")->fetchColumn(), 'preview saves nothing');
    },

    'existing campaign audiences (contacts, group) are unchanged' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteSeg group compat', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'group', 'group_id' => (string) $h->group]);
        LiteAssert::same(302, $r['status']);
        $id = (int) preg_replace('#^/marketing/campaigns/#', '', (string) $r['location']);
        LiteAssert::same(null, $a->pdo->query("SELECT audience_snapshot FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
    },

    'translations: every new key exists in all five languages, with no left-over raw keys on the pages' => function () use ($setup, $h) {
        $setup();
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $keys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'marketing.segment.') || in_array($k, ['marketing.nav.segments', 'marketing.audience.segment', 'marketing.audience.segment_hint', 'marketing.error.segment_missing', 'marketing.estimate_hint', 'marketing.estimate_matching', 'marketing.final_recipients'], true));
        LiteAssert::true(count($keys) > 120);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $strings = require $root . "/lang/{$lang}.php";
            foreach ($keys as $key) {
                LiteAssert::true(isset($strings[$key]) && trim((string) $strings[$key]) !== '', "{$lang}: {$key}");
            }
        }
        // Every registered condition and operator has a label.
        foreach ($h->a->container()->make(SegmentConditionRegistry::class)->all() as $condition) {
            LiteAssert::true(isset($en[$condition->labelKey()]), $condition->labelKey());
            foreach (array_keys($condition->operators()) as $op) {
                LiteAssert::true(isset($en['marketing.segment.op.' . $op]), 'op ' . $op);
            }
        }
        $h->a->as($h->a->admin);
        foreach (['/marketing/segments', '/marketing/segments/create?type=dynamic'] as $url) {
            [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
            parse_str($query, $q);
            LiteAssert::notContains('marketing.segment.', $h->a->request('GET', $path, $q)['body'], "raw key on {$url}");
        }
    },

    'cleanup: roll back everything' => function () use ($h) {
        if (isset($h->a)) {
            $h->a->rollBack();
        }
        LiteAssert::true(true);
    },
];
