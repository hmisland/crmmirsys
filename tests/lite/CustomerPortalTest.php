<?php

declare(strict_types=1);

/**
 * Customer Portal V1 (module customer_portal): one-time-code sign-in
 * (email, mobile, national ID → masked destination), the code rules, the
 * read-only profile, and that a customer only ever reaches their OWN
 * surveys, invoices and proformas in their OWN organization. End to end
 * through the real container, router and database (crm_local, one
 * transaction, rolled back). Codes are captured by a fake delivery.
 *
 * Tests run in order and share fixtures.
 */

use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use Modules\CustomerPortal\CustomerSession;
use Modules\CustomerPortal\Services\OtpDelivery;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';

/** Captures codes instead of sending them. */
final class FakePortalDelivery implements OtpDelivery
{
    /** @var list<array{channel: string, destination: string, code: string}> */
    public array $sent = [];
    public bool $email = true;

    public function emailAvailable(): bool
    {
        return $this->email;
    }

    public function send(string $channel, string $destination, string $code, array $organization, string $locale): bool
    {
        $this->sent[] = ['channel' => $channel, 'destination' => $destination, 'code' => $code];

        return true;
    }

    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }
}

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
        $h->a->pdo->query('SELECT 1 FROM customer_portal_otps LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or customer_portal 0001 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    if ($a->otherOrgId === null) {
        $h->reason = 'needs a second organization';
        throw new LiteSkip($h->reason);
    }
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();
    $h->fake = new FakePortalDelivery();
    $c->instance(OtpDelivery::class, $h->fake);

    // The module (as after Super Admin's "Sync"), granted to both organizations with Finance and CX.
    if ($pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'customer_portal'")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('customer_portal', 'Customer Portal', '1.0.0', 'addon', 0, 'active', '[]')");
    }
    $h->entitle = function (int $org, string $key, bool $on) use ($pdo, $c): void {
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) SELECT ?, id, ? FROM modules WHERE `key` = ? ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')->execute([$org, $on ? 1 : 0, $key]);
        $c->make(OrganizationEntitlements::class)->reset();
    };
    foreach ([$a->orgId, $a->otherOrgId] as $org) {
        foreach (['customer_portal', 'finance', 'customer_satisfaction'] as $key) {
            ($h->entitle)($org, $key, true);
        }
    }
    $pdo->exec("UPDATE modules SET status = 'active' WHERE `key` IN ('customer_portal', 'finance', 'customer_satisfaction')");
    $h->slugA = (string) $pdo->query("SELECT slug FROM organizations WHERE id = {$a->orgId}")->fetchColumn();
    $h->slugB = (string) $pdo->query("SELECT slug FROM organizations WHERE id = {$a->otherOrgId}")->fetchColumn();
    $pdo->exec("UPDATE organizations SET status = 'active', default_language = 'en' WHERE id IN ({$a->orgId}, {$a->otherOrgId})");

    $contact = function (int $org, array $fields) use ($pdo): int {
        $pdo->prepare('INSERT INTO contacts (organization_id, first_name, last_name, email, phone, national_id, address, city, description, source, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$org, $fields['first'], $fields['last'] ?? 'Portal', $fields['email'] ?? null, $fields['phone'] ?? null, $fields['nid'] ?? null, $fields['address'] ?? null, $fields['city'] ?? null, 'INTERNAL NOTE lite', 'internal-source-lite', $fields['status'] ?? 'active']);

        return (int) $pdo->lastInsertId();
    };
    $h->c = [
        'email' => $contact($a->orgId, ['first' => 'Emma', 'email' => 'Portal.A1@Example.test', 'phone' => '09121110001', 'nid' => '0012345679', 'address' => 'Street 1', 'city' => 'Tabriz']),
        'mobile' => $contact($a->orgId, ['first' => 'Mona', 'phone' => '0912 111 0002']),
        'nid' => $contact($a->orgId, ['first' => 'Nima', 'email' => 'nima.portal@example.test', 'phone' => '+989121110003', 'nid' => '۱۲۳۴۵۶۷۸۹۱']),
        'none' => $contact($a->orgId, ['first' => 'Nobody', 'nid' => '5555555555']),
        'dup1' => $contact($a->orgId, ['first' => 'Dup', 'email' => 'dup.portal@example.test']),
        'dup2' => $contact($a->orgId, ['first' => 'Dup', 'email' => 'dup.portal@example.test']),
        'inactive' => $contact($a->orgId, ['first' => 'Gone', 'email' => 'gone.portal@example.test', 'status' => 'inactive']),
        'b' => $contact($a->otherOrgId, ['first' => 'Bea', 'email' => 'portal.a1@example.test', 'nid' => '1234567891']),
    ];

    // Invoices / proformas.
    $currency = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
    $owner = (int) $a->admin['id'];
    $invoice = function (int $org, int $contactId, string $number, string $status) use ($pdo, $currency, $owner): int {
        $pdo->prepare("INSERT INTO invoices (organization_id, contact_id, owner_id, invoice_number, issue_date, currency_id, subtotal, tax_total, grand_total, paid_total, status, notes, terms)
                       VALUES (?, ?, ?, ?, '2026-01-10', ?, 1000, 90, 1090, 400, ?, 'STAFF NOTE lite', 'TERMS lite')")
            ->execute([$org, $contactId, $owner, $number, $currency, $status]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?, 'Lite consulting', 2.5000, 400, 1000)")->execute([$id]);

        return $id;
    };
    $proforma = function (int $org, int $contactId, string $number, string $status) use ($pdo, $currency, $owner): int {
        $pdo->prepare("INSERT INTO proformas (organization_id, contact_id, custom_number, owner_id, issue_date, currency_id, subtotal, tax_total, grand_total, status, notes)
                       VALUES (?, ?, ?, ?, '2026-01-05', ?, 500, 45, 545, ?, 'STAFF NOTE lite')")
            ->execute([$org, $contactId, $number, $owner, $currency, $status]);

        return (int) $pdo->lastInsertId();
    };
    $h->inv = [
        'own' => $invoice($a->orgId, $h->c['email'], 'LITE-INV-OWN', 'issued'),
        'draft' => $invoice($a->orgId, $h->c['email'], 'LITE-INV-DRAFT', 'draft'),
        'other' => $invoice($a->orgId, $h->c['mobile'], 'LITE-INV-OTHER', 'issued'),
        'orgB' => $invoice($a->otherOrgId, $h->c['b'], 'LITE-INV-ORGB', 'issued'),
    ];
    $h->pf = [
        'own' => $proforma($a->orgId, $h->c['email'], 'LITE-PF-OWN', 'sent'),
        'draft' => $proforma($a->orgId, $h->c['email'], 'LITE-PF-DRAFT', 'draft'),
        'other' => $proforma($a->orgId, $h->c['mobile'], 'LITE-PF-OTHER', 'sent'),
        'orgB' => $proforma($a->otherOrgId, $h->c['b'], 'LITE-PF-ORGB', 'sent'),
    ];

    // A survey with one score question; invitations (with their own public links) for two contacts.
    $pdo->prepare("INSERT INTO cx_surveys (organization_id, name, type, status) VALUES (?, 'Lite portal survey', 'csat', 'active')")->execute([$a->orgId]);
    $survey = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cx_survey_questions (organization_id, survey_id, kind, label, scale_min, scale_max, required) VALUES (?, ?, 'score', 'How satisfied are you?', 1, 5, 1)")->execute([$a->orgId, $survey]);
    $h->question = (int) $pdo->lastInsertId();
    $invitation = function (int $contactId, string $ref) use ($pdo, $a, $survey, $c): array {
        $pdo->prepare("INSERT INTO cx_invitations (organization_id, survey_id, source, context_type, context_ref, contact_id, status, send_after, sent_at)
                       VALUES (?, ?, 'lite', 'order', ?, ?, 'sent', NOW(), NOW())")->execute([$a->orgId, $survey, $ref, $contactId]);
        $id = (int) $pdo->lastInsertId();
        $link = OrganizationContext::runAs($a->orgId, fn () => $c->make(PublicLinkService::class)->issue('cx_survey', 'cx_invitation', $id, new \DateTimeImmutable('+7 days'), 'en', 1));
        $pdo->prepare('UPDATE cx_invitations SET public_link_id = ? WHERE id = ?')->execute([$link['id'], $id]);

        return ['id' => $id, 'token' => $link['token']];
    };
    $h->inviteOwn = $invitation($h->c['email'], 'lite-portal-1');
    $h->inviteOther = $invitation($h->c['mobile'], 'lite-portal-2');

    $h->resetLimits = fn () => $pdo->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'customer_portal.%'");
    $h->resetCodes = fn () => $pdo->exec('DELETE FROM customer_portal_otps');
    $h->signOut = function () use ($h): void {
        CustomerSession::logout();
        unset($_SESSION['_customer_portal_slug']);
        ($h->resetLimits)();
        ($h->resetCodes)();
    };
    $h->signIn = function (string $slug, string $identifier) use ($h, $a): array {
        ($h->signOut)();
        $before = count($h->fake->sent);
        $a->request('POST', '/customer/login/' . $slug, [], ['identifier' => $identifier]);
        $sent = count($h->fake->sent) > $before ? $h->fake->last() : null;
        $verify = $sent !== null ? $a->request('POST', '/customer/login/' . $slug . '/verify', [], ['code' => $sent['code']]) : null;

        return ['sent' => $sent, 'verify' => $verify];
    };
    $h->ready = true;
};

return [
    'login page: short explanation; unknown organization or one without the module → 404' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $page = $a->request('GET', '/customer/login/' . $h->slugA);
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('Sign in to your account', $page['body']);
        LiteAssert::contains('If your email is registered', $page['body']);
        LiteAssert::contains('href="/customer/login/' . $h->slugA . '/national-id"', $page['body']);
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/login/no-such-org-lite'));
        ($h->entitle)($a->otherOrgId, 'customer_portal', false);
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/login/' . $h->slugB));
        ($h->entitle)($a->otherOrgId, 'customer_portal', true);
        // The address without an organization: a browser that used a portal before goes back to it.
        LiteAssert::same('/customer/login/' . $h->slugA, $a->request('GET', '/customer/login')['location']);
        unset($_SESSION['_customer_portal_slug']);
        LiteAssert::contains('Use the sign-in link', $a->request('GET', '/customer/login')['body']);
    },

    'email sign-in: code to the registered email, stored hashed, session for that contact only' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $r = ($h->signIn)($h->slugA, '  portal.a1@EXAMPLE.test ');
        LiteAssert::same('email', $r['sent']['channel']);
        LiteAssert::same('portal.a1@example.test', $r['sent']['destination']);
        LiteAssert::same(1, preg_match('/^\d{6}$/', $r['sent']['code']));
        $row = $a->pdo->query('SELECT * FROM customer_portal_otps ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::notContains($r['sent']['code'], json_encode($row), 'code never stored in plain text');
        LiteAssert::true(password_verify($r['sent']['code'], $row['code_hash']));
        LiteAssert::same('p***@example.test', $row['destination_hint']);
        LiteAssert::same('/customer', $r['verify']['location']);
        $customer = CustomerSession::customer();
        LiteAssert::same($a->orgId, $customer['organization_id']);
        LiteAssert::same($h->c['email'], $customer['contact_id']);
        LiteAssert::true($a->pdo->query("SELECT used_at FROM customer_portal_otps WHERE id = {$row['id']}")->fetchColumn() !== null, 'code used up');
        LiteAssert::contains('Welcome, Emma Portal', $a->request('GET', '/customer')['body']);
    },

    'profile: visible, read-only — no edit UI, no update endpoint, no internal fields' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $page = $a->request('GET', '/customer/profile')['body'];
        foreach (['Emma', 'Portal.A1@Example.test', '09121110001', '0012345679', 'Street 1', 'Tabriz'] as $value) {
            LiteAssert::contains($value, $page);
        }
        LiteAssert::notContains('INTERNAL NOTE lite', $page);
        LiteAssert::notContains('internal-source-lite', $page);
        LiteAssert::same(0, preg_match('/<input(?![^>]*type="hidden")/', $page), 'no editable input');
        LiteAssert::same(1, substr_count($page, '<form'), 'the only form is "Sign out"');
        LiteAssert::contains('action="/customer/logout"', $page);
        LiteAssert::same('notfound', $a->outcome('POST', '/customer/profile', [], ['first_name' => 'Hacked']));
        LiteAssert::same('notfound', $a->outcome('POST', '/customer/profile/edit', [], ['first_name' => 'Hacked']));
        LiteAssert::same('Emma', $a->pdo->query("SELECT first_name FROM contacts WHERE id = {$h->c['email']}")->fetchColumn());
    },

    'mobile sign-in: SMS to the mobile when no email is registered; email first when it is' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $r = ($h->signIn)($h->slugA, '+98 912 111 0002');
        LiteAssert::same(['sms', '09121110002'], [$r['sent']['channel'], $r['sent']['destination']]);
        LiteAssert::same($h->c['mobile'], CustomerSession::customer()['contact_id']);
        // A contact with an email: entering the mobile still sends the code to the email (priority).
        $r = ($h->signIn)($h->slugA, '09121110003');
        LiteAssert::same(['email', 'nima.portal@example.test'], [$r['sent']['channel'], $r['sent']['destination']]);
        // Email not deliverable (log mailer in production) → the mobile is used.
        $h->fake->email = false;
        $r = ($h->signIn)($h->slugA, 'nima.portal@example.test');
        LiteAssert::same(['sms', '09121110003'], [$r['sent']['channel'], $r['sent']['destination']]);
        $h->fake->email = true;
    },

    'national ID: masked destinations to choose from, then the code; the ID alone never signs in' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signOut)();
        $base = '/customer/login/' . $h->slugA;
        $page = $a->request('POST', $base . '/national-id', [], ['national_id' => '1234567891'])['body'];
        LiteAssert::contains('n***@example.test', $page);
        LiteAssert::contains('0912***0003', $page);
        LiteAssert::notContains('nima.portal@example.test', $page, 'never the full address');
        LiteAssert::notContains('09121110003', $page);
        LiteAssert::same(null, CustomerSession::customer(), 'not signed in by the ID');
        LiteAssert::same('/customer/login/' . $h->slugA, $a->request('GET', '/customer/profile')['location'], 'still signed out');
        $before = count($h->fake->sent);
        $a->request('POST', $base . '/choose', [], ['destination' => '1']);
        LiteAssert::same(['sms', '09121110003'], [$h->fake->last()['channel'], $h->fake->last()['destination']]);
        LiteAssert::same($before + 1, count($h->fake->sent));
        // A destination index that was not offered sends nothing.
        $a->request('POST', $base . '/choose', [], ['destination' => '7']);
        LiteAssert::same($before + 1, count($h->fake->sent));
        $a->request('POST', $base . '/verify', [], ['code' => $h->fake->last()['code']]);
        LiteAssert::same($h->c['nid'], CustomerSession::customer()['contact_id']);

        // No destination, unknown ID, and the other organization's ID: one neutral answer.
        foreach (['5555555555', '9999999999'] as $nid) {
            ($h->signOut)();
            $body = $a->request('POST', $base . '/national-id', [], ['national_id' => $nid])['body'];
            LiteAssert::contains('We could not send a code with this information.', $body, $nid);
        }
        LiteAssert::contains('Enter a valid national ID', $a->request('POST', $base . '/national-id', [], ['national_id' => '12ab'])['body']);
    },

    'no enumeration: an unknown or ambiguous email gets the same answer, and nothing is sent' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $base = '/customer/login/' . $h->slugA;
        $answers = [];
        foreach (['portal.a1@example.test', 'nobody.lite@example.test', 'dup.portal@example.test', 'gone.portal@example.test'] as $email) {
            ($h->signOut)();
            $before = count($h->fake->sent);
            $res = $a->request('POST', $base, [], ['identifier' => $email]);
            $answers[] = [$res['location'], Session::getFlash('success')];
            LiteAssert::same($email === 'portal.a1@example.test' ? $before + 1 : $before, count($h->fake->sent), $email);
            LiteAssert::notContains('example.test', $a->request('GET', $base . '/verify')['body'], 'the code page shows no address');
        }
        LiteAssert::same(1, count(array_unique(array_map('json_encode', $answers))), 'identical answers');
        // The unknown identifier's "code" never works.
        $res = $a->request('POST', $base . '/verify', [], ['code' => '123456']);
        LiteAssert::same(null, CustomerSession::customer());
        LiteAssert::contains('wrong or has expired', (string) Session::getFlash('error'));
        LiteAssert::contains('Enter a valid email or mobile', $a->request('POST', $base, [], ['identifier' => 'not-an-identifier'])['body']);
    },

    'codes: wrong, expired, replayed, too many attempts' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $base = '/customer/login/' . $h->slugA;
        ($h->signOut)();
        $a->request('POST', $base, [], ['identifier' => 'portal.a1@example.test']);
        $code = $h->fake->last()['code'];
        $wrong = $code === '000000' ? '111111' : '000000';
        $a->request('POST', $base . '/verify', [], ['code' => $wrong]);
        LiteAssert::same(null, CustomerSession::customer(), 'wrong code');
        // Expired.
        $a->pdo->exec('UPDATE customer_portal_otps SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
        $a->request('POST', $base . '/verify', [], ['code' => $code]);
        LiteAssert::same(null, CustomerSession::customer(), 'expired code');

        // Replay: a used code never works again, even if the pending state is put back.
        $r = ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $used = (int) $a->pdo->query('SELECT MAX(id) FROM customer_portal_otps')->fetchColumn();
        CustomerSession::logout();
        CustomerSession::setPending($a->orgId, $used, null);
        $a->request('POST', $base . '/verify', [], ['code' => $r['sent']['code']]);
        LiteAssert::same(null, CustomerSession::customer(), 'replayed code');

        // Five wrong tries lock the code; the right one no longer works.
        ($h->signOut)();
        $a->request('POST', $base, [], ['identifier' => 'portal.a1@example.test']);
        $code = $h->fake->last()['code'];
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $a->request('POST', $base . '/verify', [], ['code' => $wrong]);
        }
        LiteAssert::contains('Too many wrong attempts', (string) Session::getFlash('error'));
        $a->request('POST', $base . '/verify', [], ['code' => $code]);
        LiteAssert::same(null, CustomerSession::customer(), 'locked code');
        LiteAssert::same(5, (int) $a->pdo->query('SELECT attempts FROM customer_portal_otps ORDER BY id DESC LIMIT 1')->fetchColumn());
    },

    'resend: cooldown, a new code replaces the old one, hourly cap per customer' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $base = '/customer/login/' . $h->slugA;
        ($h->signOut)();
        $a->request('POST', $base, [], ['identifier' => 'portal.a1@example.test']);
        $first = $h->fake->last()['code'];
        $count = count($h->fake->sent);
        $a->request('POST', $base . '/resend');
        LiteAssert::contains('Please wait', (string) Session::getFlash('error'));
        LiteAssert::same($count, count($h->fake->sent), 'nothing sent during the cooldown');
        $_SESSION['_customer_portal_pending']['at'] -= 120;
        $a->request('POST', $base . '/resend');
        LiteAssert::same($count + 1, count($h->fake->sent));
        $second = $h->fake->last()['code'];
        if ($first !== $second) {
            $a->request('POST', $base . '/verify', [], ['code' => $first]);
            LiteAssert::same(null, CustomerSession::customer(), 'the replaced code no longer works');
        }
        $a->request('POST', $base . '/verify', [], ['code' => $second]);
        LiteAssert::same($h->c['email'], CustomerSession::customer()['contact_id']);

        // Hourly cap: after 5 codes for one customer, no more are sent.
        ($h->signOut)();
        $a->pdo->exec("INSERT INTO customer_portal_otps (organization_id, contact_id, channel, destination_hint, destination_hash, code_hash, expires_at, created_at)
            SELECT {$a->orgId}, {$h->c['email']}, 'email', 'x', REPEAT('0', 64), 'x', NOW(), DATE_SUB(NOW(), INTERVAL 10 MINUTE) FROM (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) n");
        $count = count($h->fake->sent);
        $a->request('POST', $base, [], ['identifier' => 'portal.a1@example.test']);
        LiteAssert::same($count, count($h->fake->sent), 'hourly cap');
    },

    'rate limit per identifier and per client address' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signOut)();
        $base = '/customer/login/' . $h->slugA;
        for ($i = 0; $i < 5; $i++) {
            $a->request('POST', $base, [], ['identifier' => 'someone.lite@example.test']);
        }
        LiteAssert::contains('Too many attempts', $a->request('POST', $base, [], ['identifier' => 'someone.lite@example.test'])['body']);
        ($h->resetLimits)();
    },

    'logout ends the customer session only' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $res = $a->request('POST', '/customer/logout');
        LiteAssert::same('/customer/login/' . $h->slugA, $res['location']);
        LiteAssert::same(null, CustomerSession::customer());
        LiteAssert::same('/customer/login/' . $h->slugA, $a->request('GET', '/customer/invoices')['location']);
    },

    'a customer session is not a staff session' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        CurrentUser::reset();
        LiteAssert::same(null, $_SESSION['_auth_user_id'] ?? null);
        LiteAssert::same('/login', $a->request('GET', '/contacts')['location'], 'staff pages still need a staff login');
        LiteAssert::same('/login', $a->request('GET', '/finance/invoices/' . $h->inv['own'])['location']);
        $a->as($a->admin);
    },

    'invoices: only the customer\'s own, issued ones — other contact, draft, other organization → 404' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $list = $a->request('GET', '/customer/invoices')['body'];
        LiteAssert::contains('LITE-INV-OWN', $list);
        foreach (['LITE-INV-DRAFT', 'LITE-INV-OTHER', 'LITE-INV-ORGB'] as $n) {
            LiteAssert::notContains($n, $list);
        }
        $detail = $a->request('GET', '/customer/invoices/' . $h->inv['own'])['body'];
        LiteAssert::contains('Lite consulting', $detail);
        LiteAssert::contains('Outstanding', $detail);
        LiteAssert::notContains('STAFF NOTE lite', $detail);
        LiteAssert::notContains('TERMS lite', $detail);
        foreach (['draft', 'other', 'orgB'] as $k) {
            LiteAssert::same('notfound', $a->outcome('GET', '/customer/invoices/' . $h->inv[$k]), "invoice {$k}");
        }
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/invoices/abc'));
    },

    'proformas: only the customer\'s own, sent onwards — other contact, draft, other organization → 404' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $list = $a->request('GET', '/customer/proformas')['body'];
        LiteAssert::contains('LITE-PF-OWN', $list);
        foreach (['LITE-PF-DRAFT', 'LITE-PF-OTHER', 'LITE-PF-ORGB'] as $n) {
            LiteAssert::notContains($n, $list);
        }
        LiteAssert::notContains('STAFF NOTE lite', $a->request('GET', '/customer/proformas/' . $h->pf['own'])['body']);
        foreach (['draft', 'other', 'orgB'] as $k) {
            LiteAssert::same('notfound', $a->outcome('GET', '/customer/proformas/' . $h->pf[$k]), "proforma {$k}");
        }
    },

    'surveys: only the customer\'s own; answered in the portal through the invitation\'s single-use link' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $list = $a->request('GET', '/customer/surveys')['body'];
        LiteAssert::contains('href="/customer/surveys/' . $h->inviteOwn['id'] . '"', $list);
        LiteAssert::notContains('/customer/surveys/' . $h->inviteOther['id'], $list);
        LiteAssert::contains('Not answered', $list);
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/surveys/' . $h->inviteOther['id']), 'another contact\'s survey');
        LiteAssert::same('notfound', $a->outcome('POST', '/customer/surveys/' . $h->inviteOther['id'], [], ['q' . $h->question => '5']));
        $form = $a->request('GET', '/customer/surveys/' . $h->inviteOwn['id'])['body'];
        LiteAssert::contains('action="/customer/surveys/' . $h->inviteOwn['id'] . '"', $form);
        $res = $a->request('POST', '/customer/surveys/' . $h->inviteOwn['id'], [], ['q' . $h->question => '4']);
        LiteAssert::same('/customer/surveys', $res['location']);
        LiteAssert::same('responded', $a->pdo->query("SELECT status FROM cx_invitations WHERE id = {$h->inviteOwn['id']}")->fetchColumn());
        LiteAssert::same(4, (int) $a->pdo->query("SELECT score FROM cx_responses WHERE invitation_id = {$h->inviteOwn['id']}")->fetchColumn());
        LiteAssert::contains('Answered', $a->request('GET', '/customer/surveys')['body']);
        // One answer only: the portal and the anonymous link share the single-use link.
        LiteAssert::contains('already answered', $a->request('GET', '/customer/surveys/' . $h->inviteOwn['id'])['body']);
        LiteAssert::same(404, $a->request('GET', '/p/' . $h->inviteOwn['token'])['status']);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM cx_responses WHERE invitation_id = {$h->inviteOwn['id']}")->fetchColumn());
    },

    'the anonymous survey link still works' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signOut)();
        $page = $a->request('GET', '/p/' . $h->inviteOther['token']);
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('action="/p/' . $h->inviteOther['token'] . '"', $page['body']);
        $a->request('POST', '/p/' . $h->inviteOther['token'], [], ['q' . $h->question => '5']);
        LiteAssert::same('responded', $a->pdo->query("SELECT status FROM cx_invitations WHERE id = {$h->inviteOther['id']}")->fetchColumn());
    },

    'organizations are separate: the same email signs in to each organization\'s own contact' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugB, 'portal.a1@example.test');
        $customer = CustomerSession::customer();
        LiteAssert::same([$a->otherOrgId, $h->c['b']], [$customer['organization_id'], $customer['contact_id']]);
        $list = $a->request('GET', '/customer/invoices')['body'];
        LiteAssert::contains('LITE-INV-ORGB', $list);
        LiteAssert::notContains('LITE-INV-OWN', $list);
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/invoices/' . $h->inv['own']));
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/proformas/' . $h->pf['own']));
        // A code issued in organization A cannot be used at organization B's address.
        ($h->signOut)();
        $a->request('POST', '/customer/login/' . $h->slugA, [], ['identifier' => 'portal.a1@example.test']);
        $code = $h->fake->last()['code'];
        $a->request('POST', '/customer/login/' . $h->slugB . '/verify', [], ['code' => $code]);
        LiteAssert::same(null, CustomerSession::customer(), 'cross-organization code');
    },

    'module rules: no Finance → no invoices; portal revoked → signed out; contact deactivated → signed out' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        ($h->entitle)($a->orgId, 'finance', false);
        LiteAssert::same('notfound', $a->outcome('GET', '/customer/invoices'));
        LiteAssert::notContains('href="/customer/invoices"', $a->request('GET', '/customer')['body']);
        ($h->entitle)($a->orgId, 'finance', true);
        ($h->entitle)($a->orgId, 'customer_portal', false);
        LiteAssert::same('/customer/login/' . $h->slugA, $a->request('GET', '/customer')['location']);
        LiteAssert::same(null, CustomerSession::customer());
        ($h->entitle)($a->orgId, 'customer_portal', true);
        ($h->signIn)($h->slugA, 'portal.a1@example.test');
        $a->pdo->exec("UPDATE contacts SET status = 'inactive' WHERE id = {$h->c['email']}");
        LiteAssert::same('/customer/login/' . $h->slugA, $a->request('GET', '/customer/profile')['location']);
        $a->pdo->exec("UPDATE contacts SET status = 'active' WHERE id = {$h->c['email']}");
    },

    'existing staff pages still work (login page, internal invoices and proformas)' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        ($h->signOut)();
        $a->as($a->admin);
        OrganizationContext::set($a->orgId);
        CurrentUser::reset();
        LiteAssert::same(200, $a->request('GET', '/login')['status']);
        $a->as($a->admin);
        LiteAssert::same('ok', $a->outcome('GET', '/finance/invoices/' . $h->inv['own']));
        LiteAssert::same('ok', $a->outcome('GET', '/finance/proformas/' . $h->pf['own']));
        LiteAssert::same('ok', $a->outcome('GET', '/finance/invoices'));
    },

    'portal keys are translated in all five languages' => function () {
        $root = dirname(__DIR__, 2) . '/lang/';
        $en = require $root . 'en.php';
        $keys = array_values(array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'customer_portal.')));
        LiteAssert::true(count($keys) >= 95);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $lines = require $root . $lang . '.php';
            LiteAssert::same([], array_values(array_diff($keys, array_keys($lines))), "{$lang} has every portal key");
        }
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        ($h->signOut)();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM contacts WHERE description = 'INTERNAL NOTE lite'")->fetchColumn());
    },
];
