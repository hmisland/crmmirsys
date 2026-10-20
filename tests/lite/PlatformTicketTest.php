<?php

declare(strict_types=1);

/**
 * Platform tickets (organization admin → Super Admin), end to end through
 * the real container, router and database (crm_local only, one
 * transaction, rolled back).
 *
 * Tests run in order and share fixtures.
 */

use App\Application\Services\PlatformTicketService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
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
        $h->a->pdo->query('SELECT 1 FROM platform_ticket_messages LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or migration 0090 not applied: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $superId = $a->pdo->query("SELECT id FROM users WHERE is_super_admin = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($superId === false || $a->otherOrgId === null) {
        $h->reason = 'needs an active Super Admin and a second organization';
        throw new LiteSkip($h->reason);
    }
    $h->super = (new UserRepository())->findById((int) $superId);
    $h->svc = $a->container()->make(PlatformTicketService::class);
    $a->pdo->exec('DELETE FROM platform_tickets');
    $h->ticket = fn (int $id): array => $a->pdo->query("SELECT * FROM platform_tickets WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
    $h->inbox = fn (): int => (int) $a->pdo->query("SELECT COUNT(*) FROM admin_announcement_recipients WHERE user_id = {$a->admin['id']}")->fetchColumn();
    $h->errorOf = function (callable $fn): string {
        try {
            $fn();
        } catch (ValidationException $e) {
            return (string) (array_values($e->errors)[0][0] ?? '');
        }

        return '';
    };
    $h->ready = true;
};
$valid = ['subject' => 'Cannot see the Marketing menu', 'category' => 'technical', 'priority' => 'high', 'body' => "Hello,\n<script>alert(1)</script> the menu is missing."];

return [
    'org admin sends a ticket from the form; it waits for the platform team' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        LiteAssert::same(200, $a->request('GET', '/org/tickets/create')['status']);
        $response = $a->request('POST', '/org/tickets', [], $valid);
        $h->id = (int) $a->pdo->query('SELECT MAX(id) FROM platform_tickets')->fetchColumn();
        LiteAssert::same('/org/tickets/' . $h->id, $response['location']);
        LiteAssert::contains('Ticket #' . $h->id . ' sent', (string) Session::getFlash('success'));
        $t = ($h->ticket)($h->id);
        LiteAssert::same('open', $t['status']);
        LiteAssert::same($a->orgId, (int) $t['organization_id']);
        LiteAssert::same((int) $a->admin['id'], (int) $t['created_by_user_id']);
        LiteAssert::same('high', $t['priority']);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM platform_ticket_messages WHERE ticket_id = {$h->id} AND author_side = 'organization'")->fetchColumn());
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'organization' AND subject_id = {$a->orgId} AND type = 'platform_ticket.created'")->fetchColumn());

        $page = $a->request('GET', '/org/tickets/' . $h->id)['body'];
        LiteAssert::contains('Cannot see the Marketing menu', $page);
        LiteAssert::contains('&lt;script&gt;', $page, 'message escaped');
        LiteAssert::notContains('<script>alert(1)</script>', $page);
        LiteAssert::contains('Waiting for the platform team', $a->request('GET', '/org/tickets')['body']);
    },

    'validation: subject, category, priority, body; errors shown on the form' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        LiteAssert::same('platform_tickets.error.subject', ($h->errorOf)(fn () => $h->svc->create($a->admin, ['subject' => ''] + $valid)));
        LiteAssert::same('platform_tickets.error.subject', ($h->errorOf)(fn () => $h->svc->create($a->admin, ['subject' => str_repeat('x', 201)] + $valid)));
        LiteAssert::same('platform_tickets.error.category', ($h->errorOf)(fn () => $h->svc->create($a->admin, ['category' => 'billing'] + $valid)));
        LiteAssert::same('platform_tickets.error.priority', ($h->errorOf)(fn () => $h->svc->create($a->admin, ['priority' => 'low'] + $valid)));
        LiteAssert::same('platform_tickets.error.body', ($h->errorOf)(fn () => $h->svc->create($a->admin, ['body' => str_repeat('x', 5001)] + $valid)));
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        $form = $a->request('POST', '/org/tickets', [], ['body' => ''] + $valid);
        LiteAssert::same(null, $form['location']);
        LiteAssert::contains('Write a message (up to 5000 characters).', $form['body']);
        LiteAssert::contains('value="Cannot see the Marketing menu"', $form['body'], 'input kept');
    },

    'only organization admins (organization.edit) can use tickets' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        $a->as($a->noRole);
        foreach (['/org/tickets', '/org/tickets/create', '/org/tickets/' . $h->id] as $path) {
            LiteAssert::same('forbidden', $a->outcome('GET', $path), $path);
        }
        LiteAssert::same('forbidden', $a->outcome('POST', '/org/tickets', [], $valid));
        LiteAssert::same('forbidden', $a->outcome('POST', '/org/tickets/' . $h->id . '/reply', [], ['body' => 'x']));
        LiteAssert::throws(AuthorizationException::class, fn () => $h->svc->create($a->noRole, $valid));
        LiteAssert::false($h->svc->canUse($a->noRole));
        LiteAssert::notContains('href="/org/tickets"', $a->request('GET', '/help')['body'], 'no menu item / button');
        if ($a->member !== null) {
            $a->as($a->member);
            LiteAssert::same('forbidden', $a->outcome('GET', '/org/tickets'));
        }
        LiteAssert::same('csrf', (function () use ($a, $valid) { $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId); return $a->outcome('POST', '/org/tickets', [], ['_csrf' => 'bad'] + $valid); })());
    },

    'organization isolation: another organization never sees or touches the ticket' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $other = array_merge($a->admin, ['organization_id' => $a->otherOrgId]);
        LiteAssert::throws(NotFoundException::class, fn () => $h->svc->showForOrganization($other, $h->id));
        LiteAssert::throws(NotFoundException::class, fn () => $h->svc->replyAsOrganization($other, $h->id, 'hi'));
        LiteAssert::throws(NotFoundException::class, fn () => $h->svc->closeAsOrganization($other, $h->id));
        LiteAssert::same([], $h->svc->organizationPage($other, '', 50, 0));
        LiteAssert::same('open', ($h->ticket)($h->id)['status']);
    },

    'Super Admin only: list (waiting first, urgent on top), page, actions' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        foreach (['/admin/tickets', '/admin/tickets/' . $h->id] as $path) {
            LiteAssert::same('forbidden', $a->outcome('GET', $path), $path);
        }
        LiteAssert::same('forbidden', $a->outcome('POST', '/admin/tickets/' . $h->id . '/reply', [], ['body' => 'x']));
        LiteAssert::same('forbidden', $a->outcome('POST', '/admin/tickets/' . $h->id . '/status', [], ['status' => 'closed']));
        LiteAssert::throws(AuthorizationException::class, fn () => $h->svc->adminPage($a->admin, [], 10, 0));

        $h->urgent = $h->svc->create($a->admin, ['priority' => 'urgent', 'subject' => 'Urgent one'] + $valid);
        $h->closedOne = $h->svc->create($a->admin, ['priority' => 'normal', 'subject' => 'Old closed'] + $valid);
        $h->svc->closeAsOrganization($a->admin, $h->closedOne);
        // The normal ticket waits longer; only the priority rule puts the urgent (newer) one first.
        $a->pdo->exec("UPDATE platform_tickets SET last_activity_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = {$h->id}");
        $a->pdo->exec("UPDATE platform_tickets SET last_activity_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = {$h->urgent}");
        $rows = $h->svc->adminPage($h->super, [], 50, 0);
        LiteAssert::same([$h->urgent, $h->id, $h->closedOne], array_map('intval', array_column($rows, 'id')));
        LiteAssert::same(1, count($h->svc->adminPage($h->super, ['q' => 'Urgent'], 50, 0)));
        LiteAssert::same(2, $h->svc->adminCount($h->super, ['status' => 'open']));
        LiteAssert::same(2, $h->svc->openCount());

        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $list = $a->request('GET', '/admin/tickets');
        LiteAssert::same(200, $list['status']);
        LiteAssert::contains('href="/admin/tickets/' . $h->id . '"', $list['body']);
        LiteAssert::contains('nav-item__count">2<', $list['body'], 'menu count of waiting tickets');
        LiteAssert::contains('&lt;script&gt;', $a->request('GET', '/admin/tickets/' . $h->id)['body']);
    },

    'platform reply: answered, sender notified, audited; org reply makes it wait again' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $before = ($h->inbox)();
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $a->request('POST', '/admin/tickets/' . $h->id . '/reply', [], ['body' => 'Enable the Marketing module first.']);
        LiteAssert::same('Reply sent; the sender has been notified.', Session::getFlash('success'));
        LiteAssert::same('answered', ($h->ticket)($h->id)['status']);
        LiteAssert::same($before + 1, ($h->inbox)(), 'announcement for the sender');
        $title = (string) $a->pdo->query("SELECT a.title FROM admin_announcements a JOIN admin_announcement_recipients r ON r.announcement_id = a.id WHERE r.user_id = {$a->admin['id']} ORDER BY a.id DESC LIMIT 1")->fetchColumn();
        LiteAssert::contains('#' . $h->id, $title);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'organization' AND subject_id = {$a->orgId} AND type = 'platform_ticket.answered'")->fetchColumn());

        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        $page = $a->request('GET', '/org/tickets/' . $h->id)['body'];
        LiteAssert::contains('Enable the Marketing module first.', $page);
        LiteAssert::contains('note--platform', $page);
        LiteAssert::contains('Platform team', $page);
        LiteAssert::contains('nav-item__count">1<', $page, 'menu count of answered tickets');
        LiteAssert::same(1, $h->svc->answeredCount($a->admin));

        $a->request('POST', '/org/tickets/' . $h->id . '/reply', [], ['body' => 'Thanks, done.']);
        LiteAssert::same('Reply sent.', Session::getFlash('success'));
        LiteAssert::same('open', ($h->ticket)($h->id)['status']);
        LiteAssert::same(3, (int) $a->pdo->query("SELECT COUNT(*) FROM platform_ticket_messages WHERE ticket_id = {$h->id}")->fetchColumn());
        $a->request('POST', '/org/tickets/' . $h->id . '/reply', [], ['body' => '  ']);
        LiteAssert::same('Write a message (up to 5000 characters).', Session::getFlash('error'));
    },

    'reply and close; reopen by replying; close / reopen / priority by the Super Admin' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $a->request('POST', '/admin/tickets/' . $h->id . '/reply', [], ['body' => 'Solved.', 'close' => '1']);
        $t = ($h->ticket)($h->id);
        LiteAssert::same('closed', $t['status']);
        LiteAssert::same((int) $h->super['id'], (int) $t['closed_by_user_id']);
        LiteAssert::same('platform_tickets.error.already_closed', ($h->errorOf)(fn () => $h->svc->setStatusAsPlatform($h->super, $h->id, 'closed')));
        LiteAssert::same('platform_tickets.error.already_closed', ($h->errorOf)(fn () => $h->svc->closeAsOrganization($a->admin, $h->id)));

        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        LiteAssert::contains('Replying reopens it.', $a->request('GET', '/org/tickets/' . $h->id)['body']);
        $h->svc->replyAsOrganization($a->admin, $h->id, 'One more question.');
        $t = ($h->ticket)($h->id);
        LiteAssert::same('open', $t['status']);
        LiteAssert::same(null, $t['closed_at']);

        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        $a->request('POST', '/admin/tickets/' . $h->id . '/status', [], ['status' => 'closed']);
        LiteAssert::same('closed', ($h->ticket)($h->id)['status']);
        $a->request('POST', '/admin/tickets/' . $h->id . '/status', [], ['status' => 'open']);
        LiteAssert::same('Ticket reopened.', Session::getFlash('success'));
        LiteAssert::same('open', ($h->ticket)($h->id)['status']);
        LiteAssert::same('platform_tickets.error.not_closed', ($h->errorOf)(fn () => $h->svc->setStatusAsPlatform($h->super, $h->id, 'open')));
        LiteAssert::same('platform_tickets.error.status', ($h->errorOf)(fn () => $h->svc->setStatusAsPlatform($h->super, $h->id, 'answered')));
        $a->request('POST', '/admin/tickets/' . $h->id . '/priority', [], ['priority' => 'urgent']);
        LiteAssert::same('urgent', ($h->ticket)($h->id)['priority']);
        LiteAssert::same('platform_tickets.error.priority', ($h->errorOf)(fn () => $h->svc->setPriority($h->super, $h->id, 'low')));
        LiteAssert::throws(NotFoundException::class, fn () => $h->svc->showForAdmin($h->super, 999999));
    },

    'organization closes its own ticket' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        $a->request('POST', '/org/tickets/' . $h->urgent . '/close');
        LiteAssert::same('Ticket closed.', Session::getFlash('success'));
        $t = ($h->ticket)($h->urgent);
        LiteAssert::same('closed', $t['status']);
        LiteAssert::same((int) $a->admin['id'], (int) $t['closed_by_user_id']);
    },

    'Help page has the "New ticket" button and the menu item for organization admins' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        $help = $a->request('GET', '/help')['body'];
        LiteAssert::contains('href="/org/tickets/create"', $help, 'button on the Help page');
        LiteAssert::contains('href="/org/tickets" class="nav-item"', $help, 'menu item under Help');
        LiteAssert::true(strpos($help, 'href="/help" class="nav-item"') < strpos($help, 'href="/org/tickets" class="nav-item"'), 'listed right under Help');
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        LiteAssert::contains('href="/admin/tickets" class="nav-item"', $a->request('GET', '/help')['body']);
    },

    'at most 10 new tickets per user per hour' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        $a->pdo->exec("UPDATE platform_tickets SET created_at = NOW() WHERE created_by_user_id = {$a->admin['id']}");
        $count = (int) $a->pdo->query("SELECT COUNT(*) FROM platform_tickets WHERE created_by_user_id = {$a->admin['id']}")->fetchColumn();
        for ($i = $count; $i < PlatformTicketService::HOURLY_LIMIT; $i++) {
            $h->svc->create($a->admin, $valid);
        }
        LiteAssert::same('platform_tickets.error.rate_limited', ($h->errorOf)(fn () => $h->svc->create($a->admin, $valid)));
        $a->pdo->exec("UPDATE platform_tickets SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE created_by_user_id = {$a->admin['id']}");
        LiteAssert::true($h->svc->create($a->admin, $valid) > 0, 'allowed again an hour later');
    },

    'list rows line up with their headers (no ltr direction on a table cell)' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
        $org = $a->request('GET', '/org/tickets')['body'];
        LiteAssert::contains('<td><span class="ltr">#' . $h->id . '</span></td>', $org);
        LiteAssert::same(0, preg_match('/<td[^>]*class="[^"]*\bltr\b/', $org), 'no ltr table cell (org list)');
        $a->as($h->super);
        \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
        LiteAssert::same(0, preg_match('/<td[^>]*class="[^"]*\bltr\b/', $a->request('GET', '/admin/tickets')['body']), 'no ltr table cell (admin list)');
        LiteAssert::same(0, preg_match('/<d[dt][^>]*class="[^"]*\bltr\b/', $a->request('GET', '/admin/tickets/' . $h->id)['body']), 'no ltr fact cell');
    },

    'attachments: type, real content, size and count are checked before anything is saved' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        $dir = sys_get_temp_dir() . '/lite-ticket-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = function (string $name, string $content) use ($dir): array {
            file_put_contents($dir . '/' . $name, $content);

            return ['name' => $name, 'tmp_name' => $dir . '/' . $name, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
        };
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $messages = fn (): int => (int) $a->pdo->query("SELECT COUNT(*) FROM platform_ticket_messages WHERE ticket_id = {$h->id}")->fetchColumn();
        $before = $messages();
        $reply = fn (array $files) => ($h->errorOf)(fn () => $h->svc->replyAsOrganization($a->admin, $h->id, 'With a file', $files));
        LiteAssert::same('platform_tickets.error.attachment_type', $reply([$file('report.pdf', '%PDF-1.4')]), 'pdf not allowed');
        LiteAssert::same('platform_tickets.error.attachment_type', $reply([$file('page.txt', "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>")]), 'html disguised as txt');
        LiteAssert::same('platform_tickets.error.attachment_type', $reply([$file('fake.png', 'just text')]), 'text disguised as png');
        LiteAssert::same('platform_tickets.error.attachment_size', $reply([['size' => PlatformTicketService::ATTACHMENT_MAX_BYTES + 1] + $file('big.txt', 'x')]));
        LiteAssert::same('platform_tickets.error.attachment_size', $reply([['error' => UPLOAD_ERR_INI_SIZE] + $file('big2.txt', 'x')]));
        LiteAssert::same('platform_tickets.error.attachment_count', $reply(array_map(fn ($i) => $file("n{$i}.txt", 'note'), range(1, 6))));
        LiteAssert::same($before, $messages(), 'nothing saved when a file is refused');
        LiteAssert::same('platform_tickets.error.attachment_type', ($h->errorOf)(fn () => $h->svc->create($a->admin, $valid, [$file('x.exe', 'MZ')])));

        // Allowed files pass every check; the shared AttachmentService then refuses
        // a file that was not really uploaded (CLI) — the reply is kept and the
        // failure is counted, so the sender is told instead of resending.
        $failed = $h->svc->replyAsOrganization($a->admin, $h->id, 'With good files', [$file('notes.txt', "hello\n"), $file('list.csv', "a,b\n1,2\n"), $file('dot.png', $png)]);
        LiteAssert::same(3, $failed);
        LiteAssert::same($before + 1, $messages(), 'reply saved');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    },

    'attachments: listed on both sides, downloaded only through the ticket' => function () use ($setup, $h, $valid) {
        $setup();
        $a = $h->a;
        $messageId = (int) $a->pdo->query("SELECT id FROM platform_ticket_messages WHERE ticket_id = {$h->id} ORDER BY id LIMIT 1")->fetchColumn();
        $stored = $a->orgId . '/lite-ticket-' . bin2hex(random_bytes(6)) . '.txt';
        $root = dirname(__DIR__, 2) . '/storage/uploads';
        @mkdir(dirname($root . '/' . $stored), 0755, true);
        file_put_contents($root . '/' . $stored, "ticket file\n");
        $a->pdo->prepare("INSERT INTO attachments (organization_id, attachable_type, attachable_id, user_id, filename, stored_path, mime_type, size) VALUES (?, 'platform_ticket_message', ?, ?, 'notes.txt', ?, 'text/plain', 12)")
            ->execute([$a->orgId, $messageId, $a->admin['id'], $stored]);
        $att = (int) $a->pdo->lastInsertId();
        try {
            $a->as($a->admin);
            \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
            $page = $a->request('GET', '/org/tickets/' . $h->id)['body'];
            LiteAssert::contains('href="/org/tickets/' . $h->id . '/attachments/' . $att . '"', $page);
            LiteAssert::contains('notes.txt', $page);
            $download = $a->request('GET', '/org/tickets/' . $h->id . '/attachments/' . $att);
            LiteAssert::same(200, $download['status']);
            LiteAssert::same("ticket file\n", $download['body']);
            // Another ticket's URL with this file, an unknown file: 404.
            LiteAssert::same('notfound', $a->outcome('GET', '/org/tickets/' . $h->urgent . '/attachments/' . $att));
            LiteAssert::same('notfound', $a->outcome('GET', '/org/tickets/' . $h->id . '/attachments/999999'));
            LiteAssert::same(null, $h->svc->attachment(array_merge($a->admin, ['organization_id' => $a->otherOrgId]), $h->id, $att), 'other organization');
            $a->as($a->noRole);
            LiteAssert::same('forbidden', $a->outcome('GET', '/org/tickets/' . $h->id . '/attachments/' . $att));

            $a->as($h->super);
            \App\Infrastructure\Repositories\OrganizationContext::reset(); // a real Super Admin request has no organization
            LiteAssert::contains('href="/admin/tickets/' . $h->id . '/attachments/' . $att . '"', $a->request('GET', '/admin/tickets/' . $h->id)['body']);
            LiteAssert::same("ticket file\n", $a->request('GET', '/admin/tickets/' . $h->id . '/attachments/' . $att)['body']);
            $a->as($a->admin);
            \App\Infrastructure\Repositories\OrganizationContext::set($a->orgId);
            LiteAssert::same('forbidden', $a->outcome('GET', '/admin/tickets/' . $h->id . '/attachments/' . $att));
        } finally {
            @unlink($root . '/' . $stored);
        }
    },

    'ticket keys are translated in all five languages' => function () {
        $root = dirname(__DIR__, 2) . '/lang/';
        $en = require $root . 'en.php';
        $keys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'platform_tickets.'));
        LiteAssert::true(count($keys) >= 60);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $other = require $root . $lang . '.php';
            LiteAssert::same([], array_values(array_diff($keys, array_keys($other))), "{$lang} has every ticket key");
        }
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM platform_tickets WHERE subject = 'Urgent one'")->fetchColumn());
    },
];
