<?php

declare(strict_types=1);

/**
 * Patch A — invoice cancel hardening (docs/invoice-cancel-note.md).
 *
 * - Draft: cancel allowed as before (reason optional, no period lock).
 * - Issued: cancel only with NO payment, NO credit note, issue date in an
 *   OPEN period, and a reason; recorded in the timeline with user + time.
 * - Old data (cancelled invoices that already have payments) is never
 *   changed; the invoice page shows a warning.
 *
 * Payments / credit notes / issue are set as stored values (their services
 * open their own DB transaction, which can't nest in this suite's rollback
 * transaction); cancel() itself joins the open transaction.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use Modules\Finance\Services\InvoiceService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$h->reason = '';

$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $a = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    if ($a->member === null) {
        $a->rollBack();
        $h->reason = 'needs a second active user in the organization';
        throw new LiteSkip($h->reason);
    }
    $finance = $a->pdo->query("SELECT id FROM modules WHERE `key` = 'finance'")->fetchColumn();
    if ($finance === false) {
        $a->rollBack();
        $h->reason = 'Finance not installed in crm_local';
        throw new LiteSkip($h->reason);
    }
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([(int) $finance]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, (int) $finance]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $finance]);
    // No closed period may cover the dates these tests use, except the one a test creates.
    $a->pdo->prepare("DELETE FROM financial_periods WHERE organization_id = ? AND status = 'closed'")->execute([$a->orgId]);
    $a->as($a->admin);
    $h->currency = (string) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $h->app = $a;

    return $a;
};
$svc = fn (LocalApp $a): InvoiceService => $a->container()->make(InvoiceService::class);

/** A draft invoice through the real service, then given the scenario's stored status / paid_total. */
$invoice = function (LocalApp $a, string $status = 'issued', string $paid = '0', string $issueDate = '') use ($h, $svc): int {
    $inv = $svc($a)->create($a->admin, [
        'currency_id' => $h->currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => $issueDate !== '' ? $issueDate : date('Y-m-d'),
        'items' => [['description' => 'LiteTest PA line', 'quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'none', 'discount_value' => '0']],
    ]);
    $a->pdo->prepare('UPDATE invoices SET status = ?, paid_total = ? WHERE id = ?')->execute([$status, $paid, (int) $inv['id']]);

    return (int) $inv['id'];
};
$credit = function (LocalApp $a, int $invoiceId, string $amount): void {
    $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, CURDATE(), 'LiteTest PA', ?, ?)")
        ->execute([$a->orgId, $invoiceId, 'LT-PA-' . bin2hex(random_bytes(4)), $amount, (int) $a->admin['id']]);
};
$row = fn (LocalApp $a, int $id): array => $a->pdo->query("SELECT status, paid_total FROM invoices WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
$events = fn (LocalApp $a, int $id): array => $a->pdo->query("SELECT * FROM timeline_events WHERE subject_type = 'invoice' AND subject_id = {$id} AND type = 'invoice_cancelled'")->fetchAll(\PDO::FETCH_ASSOC);
$creditCount = fn (LocalApp $a, int $id): int => (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = {$id}")->fetchColumn();
/** Asserts cancel() is refused with $field / $key and that NOTHING changed. */
$refused = function (LocalApp $a, int $id, string $reason, string $field, ?string $key) use ($svc, $row, $events, $creditCount): void {
    $before = [$row($a, $id), $creditCount($a, $id), count($events($a, $id))];
    try {
        $svc($a)->cancel($a->admin, $id, $reason);
        throw new LiteAssertionFailed("cancel #{$id} was accepted");
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors[$field]), "expected {$field}, got " . json_encode($e->errors, JSON_UNESCAPED_UNICODE));
        if ($key !== null) {
            $en = require dirname(__DIR__, 2) . '/lang/en.php';
            LiteAssert::same($en[$key], $e->errors[$field][0] ?? null, 'message');
        }
    }
    LiteAssert::same($before, [$row($a, $id), $creditCount($a, $id), count($events($a, $id))], 'status, paid_total, credit notes and timeline unchanged');
};

$tests = [];

$tests['1. draft: cancel allowed without a reason (behaviour kept), recorded in the timeline'] = function () use ($boot, $invoice, $svc, $row, $events) {
    $a = $boot();
    $a->locale('en');
    $id = $invoice($a, 'draft');
    $svc($a)->cancel($a->admin, $id);
    LiteAssert::same('cancelled', $row($a, $id)['status']);
    LiteAssert::same(1, count($events($a, $id)));
};

$tests['2. draft dated in a CLOSED period: still allowed (no period lock on drafts)'] = function () use ($boot, $invoice, $svc, $row) {
    $a = $boot();
    $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '2001-01-01', '2001-12-31', 'closed')")->execute([$a->orgId]);
    $id = $invoice($a, 'draft', '0', '2001-06-01');
    $svc($a)->cancel($a->admin, $id);
    LiteAssert::same('cancelled', $row($a, $id)['status']);
};

$tests['3. issued, no payment, no credit note, open period, with reason → cancelled; timeline has reason, user and time'] = function () use ($boot, $invoice, $svc, $row, $events) {
    $a = $boot();
    $id = $invoice($a);
    $svc($a)->cancel($a->admin, $id, '  Duplicate of another invoice  ');
    LiteAssert::same('cancelled', $row($a, $id)['status']);
    $e = $events($a, $id);
    LiteAssert::same(1, count($e));
    LiteAssert::same('Invoice cancelled: Duplicate of another invoice', $e[0]['description']);
    LiteAssert::same((int) $a->admin['id'], (int) $e[0]['actor_user_id']);
    LiteAssert::true(strtotime((string) $e[0]['created_at']) >= time() - 600, 'time recorded');
};

$tests['4. issued without a reason (empty / spaces) → 422, nothing changed'] = function () use ($boot, $invoice, $refused) {
    $a = $boot();
    $a->locale('en');
    $id = $invoice($a);
    $refused($a, $id, '', 'reason', 'invoices.error.cancel_reason_required');
    $refused($a, $id, '   ', 'reason', 'invoices.error.cancel_reason_required');
};

$tests['5. reason longer than 450 characters → 422, nothing changed'] = function () use ($boot, $invoice, $refused) {
    $a = $boot();
    $a->locale('en');
    $refused($a, $invoice($a), str_repeat('x', 451), 'reason', 'invoices.error.cancel_reason_too_long');
};

$tests['6. issued with a PARTIAL payment → 422 has_payments, nothing changed'] = function () use ($boot, $invoice, $refused) {
    $a = $boot();
    $a->locale('en');
    $refused($a, $invoice($a, 'issued', '250'), 'reason', 'status', 'invoices.cancel_blocked.has_payments');
};

$tests['7. issued and FULLY paid → 422 has_payments, nothing changed'] = function () use ($boot, $invoice, $refused) {
    $a = $boot();
    $a->locale('en');
    $refused($a, $invoice($a, 'issued', '1000'), 'reason', 'status', 'invoices.cancel_blocked.has_payments');
};

$tests['8. issued with a PARTIAL credit note → 422 has_credit_notes, nothing changed'] = function () use ($boot, $invoice, $credit, $refused) {
    $a = $boot();
    $a->locale('en');
    $id = $invoice($a);
    $credit($a, $id, '100');
    $refused($a, $id, 'reason', 'status', 'invoices.cancel_blocked.has_credit_notes');
};

$tests['9. issued and FULLY credited (balance 0, no payment) → still 422 has_credit_notes'] = function () use ($boot, $invoice, $credit, $refused) {
    $a = $boot();
    $a->locale('en');
    $id = $invoice($a);
    $credit($a, $id, '1000');
    $refused($a, $id, 'reason', 'status', 'invoices.cancel_blocked.has_credit_notes');
};

$tests['10. issued, dated in a CLOSED financial period → 422 period_locked; the same invoice in an open period is cancellable'] = function () use ($boot, $invoice, $refused, $svc, $row) {
    $a = $boot();
    $a->locale('en');
    $a->pdo->prepare("INSERT INTO financial_periods (organization_id, starts_on, ends_on, status) VALUES (?, '2002-01-01', '2002-12-31', 'closed')")->execute([$a->orgId]);
    $locked = $invoice($a, 'issued', '0', '2002-03-15');
    $refused($a, $locked, 'reason', 'status', 'invoices.cancel_blocked.period_locked');
    $a->pdo->exec("UPDATE financial_periods SET status = 'open' WHERE organization_id = {$a->orgId} AND starts_on = '2002-01-01'");
    $svc($a)->cancel($a->admin, $locked, 'Reopened period');
    LiteAssert::same('cancelled', $row($a, $locked)['status']);
};

$tests['11. already cancelled → 422; a payment-bearing cancelled invoice (old data) is NOT changed'] = function () use ($boot, $invoice, $credit, $refused, $row) {
    $a = $boot();
    $legacy = $invoice($a, 'cancelled', '400');
    $credit($a, $legacy, '50');
    $refused($a, $legacy, 'reason', 'status', null);
    LiteAssert::same(['status' => 'cancelled', 'paid_total' => $row($a, $legacy)['paid_total']], $row($a, $legacy));
    LiteAssert::same(0, bccomp($row($a, $legacy)['paid_total'], '400', 8), 'paid_total kept');
};

$tests['12. through the route: reason posted → cancelled with translated flash; refused → translated flash, nothing changed'] = function () use ($boot, $invoice, $row) {
    $a = $boot();
    $a->as($a->admin);
    $fa = require dirname(__DIR__, 2) . '/lang/fa.php';
    $a->locale('fa');
    $ok = $invoice($a);
    $r = $a->request('POST', "/finance/invoices/{$ok}/cancel", [], ['reason' => 'LiteTest route']);
    LiteAssert::same(302, $r['status']);
    LiteAssert::same('cancelled', $row($a, $ok)['status']);
    LiteAssert::same($fa['invoices.flash.cancelled'], \App\Infrastructure\Security\Session::getFlash('success'));
    $paid = $invoice($a, 'issued', '10');
    $a->request('POST', "/finance/invoices/{$paid}/cancel", [], ['reason' => 'x']);
    LiteAssert::same($fa['invoices.cancel_blocked.has_payments'], \App\Infrastructure\Security\Session::getFlash('error'));
    LiteAssert::same('issued', $row($a, $paid)['status']);
    $a->locale('en');
};

$tests['13. security: no invoices.cancel → 403; another organization\'s invoice → 404; bad CSRF → refused; nothing changed'] = function () use ($boot, $h, $invoice, $row) {
    $a = $boot();
    $id = $invoice($a);
    $a->as($a->member);
    LiteAssert::same('forbidden', $a->outcome('POST', "/finance/invoices/{$id}/cancel", [], ['reason' => 'x']));
    $a->as($a->admin);
    LiteAssert::same('csrf', $a->outcome('POST', "/finance/invoices/{$id}/cancel", [], ['reason' => 'x', '_csrf' => 'wrong']));
    LiteAssert::same('issued', $row($a, $id)['status']);
    if ($a->otherOrgId !== null) {
        $owner = $a->pdo->query("SELECT id FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
        $owner = $owner === false ? (int) $a->admin['id'] : (int) $owner;
        $a->pdo->prepare("INSERT INTO invoices (organization_id, owner_id, issue_date, currency_id, pricing_mode, grand_total, status) VALUES (?, ?, CURDATE(), ?, 'tax_exclusive', 10, 'issued')")
            ->execute([$a->otherOrgId, $owner, $h->currency]);
        $foreign = (int) $a->pdo->lastInsertId();
        LiteAssert::same('notfound', $a->outcome('POST', "/finance/invoices/{$foreign}/cancel", [], ['reason' => 'x']));
        LiteAssert::same('issued', $a->pdo->query("SELECT status FROM invoices WHERE id = {$foreign}")->fetchColumn());
    }
};

$tests['14. invoice page: blocked → disabled button + reason; allowed → reason field; cancelled-with-payment warning; cancel history'] = function () use ($boot, $invoice, $svc) {
    $a = $boot();
    $a->as($a->admin);
    $a->locale('en');
    $en = require dirname(__DIR__, 2) . '/lang/en.php';
    $blocked = $invoice($a, 'issued', '5');
    $page = $a->request('GET', "/finance/invoices/{$blocked}")['body'];
    LiteAssert::contains(htmlspecialchars($en['invoices.cancel_blocked.has_payments'], ENT_QUOTES), $page);
    LiteAssert::notContains('id="cancel_reason"', $page, 'no cancel reason field when blocked');
    LiteAssert::notContains("action=\"/finance/invoices/{$blocked}/cancel\"", $page, 'no cancel form when blocked');
    LiteAssert::contains(' disabled>', $page);

    $allowed = $invoice($a);
    $page = $a->request('GET', "/finance/invoices/{$allowed}")['body'];
    LiteAssert::contains('id="cancel_reason"', $page);
    LiteAssert::contains("action=\"/finance/invoices/{$allowed}/cancel\"", $page);

    $svc($a)->cancel($a->admin, $allowed, 'LiteTest history reason');
    $page = $a->request('GET', "/finance/invoices/{$allowed}")['body'];
    LiteAssert::contains(htmlspecialchars($en['invoices.cancellation_history'], ENT_QUOTES), $page);
    LiteAssert::contains('LiteTest history reason', $page);
    LiteAssert::contains(htmlspecialchars(trim($a->admin['first_name'] . ' ' . ($a->admin['last_name'] ?? '')), ENT_QUOTES), $page);
    LiteAssert::notContains(htmlspecialchars($en['invoices.cancelled_with_payments'], ENT_QUOTES), $page, 'no payment → no warning');

    $legacy = $invoice($a, 'cancelled', '300');
    $page = $a->request('GET', "/finance/invoices/{$legacy}")['body'];
    LiteAssert::contains(htmlspecialchars($en['invoices.cancelled_with_payments'], ENT_QUOTES), $page);
};

$tests['15. all five languages: invoice page and blocked reason render translated, no raw invoices.* key'] = function () use ($boot, $invoice) {
    $a = $boot();
    $a->as($a->admin);
    $blocked = $invoice($a, 'issued', '5');
    $allowed = $invoice($a);
    $legacy = $invoice($a, 'cancelled', '7');
    $problems = [];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        $a->locale($lang);
        foreach ([$blocked, $allowed, $legacy] as $id) {
            $r = $a->request('GET', "/finance/invoices/{$id}");
            $text = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $r['body'])));
            preg_match_all('/\binvoices\.(?:cancel|error|flash|cancelled|cancellation)[a-z_.]*\b/', $text, $m);
            if ($r['status'] !== 200 || $m[0] !== []) {
                $problems[] = "{$lang} #{$id}: {$r['status']} " . implode(',', $m[0]);
            }
        }
        $b = $a->request('GET', "/finance/invoices/{$blocked}")['body'];
        if (!str_contains($b, htmlspecialchars($strings['invoices.cancel_blocked.has_payments'], ENT_QUOTES))) {
            $problems[] = "{$lang}: blocked reason not shown";
        }
    }
    $a->locale('en');
    LiteAssert::same([], $problems, implode(' | ', $problems));
};

$tests['16. regression: payment and credit-note recording paths are untouched (their rules still apply to issued invoices only)'] = function () use ($boot, $invoice, $svc) {
    $a = $boot();
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Finance/Services/PaymentService.php')
        . (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Finance/Services/CreditNoteService.php');
    LiteAssert::true(str_contains($source, "\$invoice['status'] !== 'issued'"), 'payments / credit notes still require issued');
    // A cancelled invoice still cannot receive a payment.
    $cancelled = $invoice($a, 'cancelled', '0');
    LiteAssert::throws(ValidationException::class, fn () => $a->container()->make(\Modules\Finance\Services\PaymentService::class)->record($a->admin, $cancelled, ['amount' => '1', 'payment_date' => date('Y-m-d')]));
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTest PA'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'invoice' AND description LIKE '%LiteTest%'")->fetchColumn());
};

return $tests;
