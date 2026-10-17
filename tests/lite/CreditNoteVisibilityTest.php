<?php

declare(strict_types=1);

/**
 * Patch B — credit-note visibility on the invoice page, print and PDF.
 *
 * - Credit-note details (list, number, date, reason, amount, credited and
 *   effective / payable totals) only with credit_notes.view.
 * - Outstanding / remaining and the payment status still account for
 *   every credit note, whoever looks.
 * - credit_notes.view and credit_notes.create stay independent.
 *
 * Users with an exact permission set are built from a temporary role of
 * this organization (organization scope) inside the suite's transaction.
 * Credit notes are stored rows (CreditNoteService opens its own
 * transaction, which can't nest here). crm_local only, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

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
    $a->pdo->prepare("DELETE FROM financial_periods WHERE organization_id = ? AND status = 'closed'")->execute([$a->orgId]);
    $a->as($a->admin);
    $a->locale('en');
    $h->currency = (string) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $h->app = $a;

    return $a;
};

/** The member, acting with a temporary role that holds exactly $permissions (organization scope). */
$userWith = function (LocalApp $a, array $permissions): array {
    $a->pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')
        ->execute([$a->orgId, 'litetest_pb_' . bin2hex(random_bytes(4)), 'LiteTest PB']);
    $roleId = (int) $a->pdo->lastInsertId();
    foreach ($permissions as $key) {
        $a->pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$roleId, $key]);
    }

    return array_merge($a->member, ['role_id' => $roleId]);
};

/** An issued invoice with a known total (1000) and stored paid_total, owned by $owner (admin by default). */
$invoice = function (LocalApp $a, string $paid = '0', ?array $owner = null, string $status = 'issued') use ($h): int {
    $inv = $a->container()->make(InvoiceService::class)->create($a->admin, [
        'currency_id' => $h->currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'),
        'items' => [['description' => 'LiteTest PB line', 'quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'none', 'discount_value' => '0']],
    ]);
    $a->pdo->prepare('UPDATE invoices SET subtotal = 1000, tax_total = 0, invoice_discount = 0, grand_total = 1000, status = ?, paid_total = ?, owner_id = ? WHERE id = ?')
        ->execute([$status, $paid, (int) ($owner ?? $a->admin)['id'], (int) $inv['id']]);

    return (int) $inv['id'];
};
/** A stored credit note with a recognisable number / reason; returns the number. */
$credit = function (LocalApp $a, int $invoiceId, string $amount, ?int $orgId = null): string {
    $number = 'LTCN' . strtoupper(bin2hex(random_bytes(3)));
    $a->pdo->prepare("INSERT INTO credit_notes (organization_id, invoice_id, credit_note_number, issue_date, reason, amount, issued_by_user_id) VALUES (?, ?, ?, '2026-03-17', 'LiteTestPBReason', ?, ?)")
        ->execute([$orgId ?? $a->orgId, $invoiceId, $number, $amount, (int) $a->admin['id']]);

    return $number;
};

$en = require dirname(__DIR__, 2) . '/lang/en.php';
/** Visible text of an HTML page (scripts / styles dropped). */
$text = fn (string $html): string => html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html)));
$page = function (LocalApp $a, array $user, string $path): string {
    $a->as($user);
    try {
        return $a->request('GET', $path)['body'];
    } finally {
        $a->as($a->admin);
    }
};
/**
 * The streams of a PDF, each cut by its own /Length (PDF spec), decoded when
 * FlateDecode. Cutting at "\nendstream" instead was flaky: a compressed stream
 * whose last byte happens to be "\r" lost that byte to the line-break match,
 * so it no longer inflated and the page's text vanished (≈1 PDF in 256).
 *
 * @return string[]
 */
$pdfStreams = function (string $pdf): array {
    preg_match_all('#>>\s*stream(\r\n|\n)#', $pdf, $m, PREG_OFFSET_CAPTURE);
    $streams = [];
    foreach ($m[0] as $hit) {
        // The stream's dictionary: from its "N 0 obj" up to the "stream" keyword (may hold nested << >>).
        $objAt = strrpos(substr($pdf, 0, $hit[1]), ' obj');
        $dict = substr($pdf, $objAt === false ? 0 : $objAt, $hit[1] - ($objAt === false ? 0 : $objAt));
        if (preg_match('#/Length\s+(\d++)(?!\s+\d+\s+R)#', $dict, $len) !== 1) {
            continue; // indirect /Length: not written by mPDF
        }
        $data = substr($pdf, $hit[1] + strlen($hit[0]), (int) $len[1]);
        if (str_contains($dict, '/FlateDecode')) {
            $data = @gzuncompress($data);
        }
        if (is_string($data)) {
            $streams[] = $data;
        }
    }

    return $streams;
};
/** The text drawn in a PDF made by mPDF (UTF-16BE string operands of Tj, or of TJ arrays; literal or hex). */
$pdfText = function (string $pdf) use ($pdfStreams): string {
    LiteAssert::same('%PDF', substr($pdf, 0, 4), 'a PDF document');
    $literal = fn (string $run): string => (string) preg_replace_callback('#\\\\([0-7]{1,3}|.)#s', fn ($x) => match (true) {
        ctype_digit($x[1]) => chr(octdec($x[1])), $x[1] === 'n' => "\n", $x[1] === 'r' => "\r", $x[1] === 't' => "\t", default => $x[1],
    }, $run);
    $strings = fn (string $operands): string => implode('', array_map(
        fn (array $s): string => $s[1] !== '' ? $literal($s[1]) : (string) hex2bin(strlen($h = preg_replace('/\s+/', '', $s[2])) % 2 ? $h . '0' : $h),
        preg_match_all('#\(((?:\\\\.|[^\\\\)])*)\)|<([0-9A-Fa-f\s]*)>#s', $operands, $parts, PREG_SET_ORDER) ? $parts : []
    ));
    $out = '';
    foreach ($pdfStreams($pdf) as $data) {
        preg_match_all('#(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>)\s*Tj|\[((?:\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|[^\]])*)\]\s*TJ#s', $data, $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            $out .= mb_convert_encoding($strings(($op[2] ?? '') !== '' ? $op[2] : $op[1]), 'UTF-8', 'UTF-16BE') . ' ';
        }
    }
    LiteAssert::true(trim($out) !== '', 'text extracted from the PDF');

    return preg_replace('/\s+/', ' ', $out);
};
/**
 * Whether $s shows the AMOUNT $n: not as part of a longer number or an id
 * ("#10700", "1,700", "3,000"), but as "700", "-700", "700.00". Bare
 * substring checks were flaky: the page shows the invoice id ("Invoice
 * #10700"), which in time contains "300" / "700".
 */
$amountPattern = fn (string $n): string => '/(?<![\d.,])' . preg_quote($n, '/') . '(?![\d,])/';
$hasAmount = fn (string $s, string $n): bool => preg_match($amountPattern($n), $s) === 1;
/** $s without the amount $n (e.g. the outstanding figure), leaving longer numbers / ids intact. */
$without = fn (string $s, string $n): string => (string) preg_replace($amountPattern($n), '', $s);
/** Section of the invoice page from the credit-notes heading on ('' when absent). */
$creditSection = function (string $html) use ($en): string {
    // The heading may carry a "?" help button after its text (Finance help guides), so match its opening only.
    $at = strpos($html, '<h2>' . htmlspecialchars($en['credit_notes.title'], ENT_QUOTES));

    return $at === false ? '' : substr($html, $at, strpos($html, '</section>', $at) - $at);
};
/** Asserts none of the credit-note details of invoice ($number) appears in $s. */
$noDetails = function (string $s, string $number, string $label) use ($en, $hasAmount): void {
    LiteAssert::notContains($number, $s, "{$label}: no credit-note number");
    LiteAssert::notContains('LiteTestPBReason', $s, "{$label}: no reason");
    LiteAssert::notContains('2026-03-17', $s, "{$label}: no credit-note date");
    LiteAssert::notContains('2026/03/17', $s, "{$label}: no credit-note date (localised)");
    LiteAssert::false($hasAmount($s, '300'), "{$label}: no credit amount (300)");
    LiteAssert::false($hasAmount($s, '700'), "{$label}: no effective / payable total (1000 − 300 = 700)");
    LiteAssert::notContains($en['credit_notes.total_credited'], $s, "{$label}: no Total credited");
    LiteAssert::notContains($en['credit_notes.effective_total'], $s, "{$label}: no Effective total");
};

$tests = [];

$tests['1. org admin (view + create): page lists the credit note and shows credited / effective totals and the form'] = function () use ($boot, $invoice, $credit, $page, $text, $creditSection, $en, $hasAmount) {
    $a = $boot();
    $id = $invoice($a, '100');
    $number = $credit($a, $id, '300');
    $html = $page($a, $a->admin, "/finance/invoices/{$id}");
    $t = $text($html);
    foreach ([$number, 'LiteTestPBReason', '2026-03-17', $en['credit_notes.total_credited'], $en['credit_notes.effective_total']] as $needle) {
        LiteAssert::contains($needle, $t);
    }
    LiteAssert::true($hasAmount($t, '-300'), 'credited amount');
    LiteAssert::true($hasAmount($t, '700'), 'effective total');
    LiteAssert::true($hasAmount($t, '600'), 'outstanding = 1000 − 300 − 100');
    LiteAssert::contains('id="cn_amount"', $html, 'create form');
    LiteAssert::contains('max="700', $html, 'form capped at the effective total');
};

$tests['2. without credit_notes.view (and without create): no credit-note section or detail at all; outstanding and status still net of credits'] = function () use ($boot, $userWith, $invoice, $credit, $page, $text, $creditSection, $noDetails, $en, $without) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view', 'payments.view']);
    $id = $invoice($a, '100');
    $number = $credit($a, $id, '300');
    $html = $page($a, $user, "/finance/invoices/{$id}");
    LiteAssert::contains('LiteTest PB line', $html, 'the invoice page itself is shown');
    LiteAssert::same('', $creditSection($html), 'no credit-notes section');
    LiteAssert::notContains('/credit-notes', $html, 'no create form');
    $t = $text($html);
    $noDetails($without($t, '600'), $number, 'page');
    LiteAssert::contains($en['payments.outstanding'] . ': 600', $t, 'outstanding = 1000 − 300 − 100');
    LiteAssert::contains($en['payments.status.partial'], $t, 'payment status');
};

$tests['3. without credit_notes.view: fully credited → status "paid" and outstanding 0, still with no credit detail'] = function () use ($boot, $userWith, $invoice, $credit, $page, $text, $noDetails, $en, $without) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view']);
    $id = $invoice($a, '700');
    $number = $credit($a, $id, '300');
    $t = $text($page($a, $user, "/finance/invoices/{$id}"));
    LiteAssert::contains($en['payments.outstanding'] . ': 0', $t, 'outstanding = 1000 − 300 − 700');
    LiteAssert::contains($en['payments.status.paid'], $t, 'paid once credits are counted');
    $noDetails($without($t, '700'), $number, 'page'); // 700 here is the paid amount, not a credit figure
};

$tests['4. credit_notes.create WITHOUT view: form shown (no amount cap leaked), list hidden; the create route is allowed'] = function () use ($boot, $userWith, $invoice, $credit, $page, $text, $creditSection, $noDetails, $without) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view', 'credit_notes.create']);
    $id = $invoice($a, '100');
    $number = $credit($a, $id, '300');
    $html = $page($a, $user, "/finance/invoices/{$id}");
    $section = $creditSection($html);
    LiteAssert::contains('id="cn_amount"', $section, 'create form shown');
    LiteAssert::notContains('max=', $section, 'no creditable amount in the form');
    LiteAssert::notContains('<table', $section, 'no credit-note list');
    $noDetails($without($text($html), '600'), $number, 'page');
    // Permission path: the route and the service accept this user (an empty reason → validation 422, not 403).
    $a->as($user);
    $outcome = $a->outcome('POST', "/finance/invoices/{$id}/credit-notes", [], ['amount' => '1', 'reason' => '', 'issue_date' => date('Y-m-d')]);
    $a->as($a->admin);
    LiteAssert::same('ok', $outcome, 'create still allowed by credit_notes.create');
};

$tests['5. credit_notes.view WITHOUT create: list visible, no create form, create route forbidden'] = function () use ($boot, $userWith, $invoice, $credit, $page, $creditSection) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view', 'credit_notes.view']);
    $id = $invoice($a);
    $number = $credit($a, $id, '300');
    $section = $creditSection($page($a, $user, "/finance/invoices/{$id}"));
    LiteAssert::contains($number, $section, 'list visible');
    LiteAssert::contains('LiteTestPBReason', $section);
    LiteAssert::notContains('id="cn_amount"', $section, 'no create form');
    $a->as($user);
    $outcome = $a->outcome('POST', "/finance/invoices/{$id}/credit-notes", [], ['amount' => '1', 'reason' => 'x', 'issue_date' => date('Y-m-d')]);
    $a->as($a->admin);
    LiteAssert::same('forbidden', $outcome);
};

$tests['6. print: admin gets the Credited row and payable; a user without view gets neither, but the right remaining and status'] = function () use ($boot, $userWith, $invoice, $credit, $page, $text, $noDetails, $en, $hasAmount, $without) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view']);
    $id = $invoice($a, '100');
    $number = $credit($a, $id, '300');
    $admin = $text($page($a, $a->admin, "/finance/invoices/{$id}/print"));
    LiteAssert::contains($en['print.credited'], $admin);
    LiteAssert::contains($en['print.payable'], $admin);
    LiteAssert::true($hasAmount($admin, '300'), 'credited 300');
    LiteAssert::true($hasAmount($admin, '700'), 'payable 700');
    $plain = $text($page($a, $user, "/finance/invoices/{$id}/print"));
    LiteAssert::notContains($en['print.credited'], $plain, 'no Credited row');
    LiteAssert::notContains($en['print.payable'], $plain, 'no Payable row');
    LiteAssert::notContains($en['print.words_payable'], $plain, 'no payable in words');
    LiteAssert::contains($en['print.grand_total'], $plain, 'grand total shown instead');
    LiteAssert::true($hasAmount($plain, '600'), 'remaining = 1000 − 300 − 100');
    LiteAssert::contains($en['payments.status.partial'], $plain, 'status');
    $noDetails($without($plain, '600'), $number, 'print');
};

$tests['7. PDF (real mPDF output): same rule as print'] = function () use ($boot, $userWith, $invoice, $credit, $page, $pdfText, $noDetails, $en, $hasAmount, $without) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view']);
    $id = $invoice($a, '100');
    $number = $credit($a, $id, '300');
    $admin = $pdfText($page($a, $a->admin, "/finance/invoices/{$id}/pdf"));
    LiteAssert::contains($en['print.credited'], $admin);
    LiteAssert::contains($en['print.payable'], $admin);
    LiteAssert::true($hasAmount($admin, '300'), 'credited 300');
    $plain = $pdfText($page($a, $user, "/finance/invoices/{$id}/pdf"));
    LiteAssert::notContains($en['print.credited'], $plain, 'no Credited row');
    LiteAssert::notContains($en['print.payable'], $plain, 'no Payable row');
    LiteAssert::contains($en['print.grand_total'], $plain);
    LiteAssert::true($hasAmount($plain, '600'), 'remaining');
    $noDetails($without($plain, '600'), $number, 'pdf');
};

$tests['7a. amount checks are exact: an id or a longer number never counts as the amount (the old bare-substring flake)'] = function () use ($hasAmount, $without, $noDetails) {
    // What made tests 2 / 4 flaky: the page shows "Invoice #10700" once ids reach that range.
    foreach (['Invoice #10700 (not issued yet)', 'Invoice #8300', 'Total 1,700.00', 'Total 3,000', 'id 17003', 'v1.300'] as $s) {
        LiteAssert::false($hasAmount($s, '700') || $hasAmount($s, '300'), "not an amount: {$s}");
    }
    foreach (['700', '700.00', '-300', 'Credited: 300.00 USD', '(700)', 'payable 700'] as $s) {
        LiteAssert::true($hasAmount($s, '700') || $hasAmount($s, '300'), "an amount: {$s}");
    }
    LiteAssert::same('Outstanding:  | Invoice #10600', $without('Outstanding: 600 | Invoice #10600', '600'), 'only the amount is removed, the id stays');
    $noDetails('Invoice #10700 #8300 Outstanding: 600', 'LTCN-NONE', 'id-only page'); // passes: no credit figure, only ids
    try {
        $noDetails('Invoice #10700 Payable 700.00', 'LTCN-NONE', 'leaking page');
    } catch (LiteAssertionFailed) {
        return; // a real 700 is still caught
    }
    throw new LiteAssertionFailed('a real payable 700 was not detected');
};

$tests['7b. PDF streams are cut by /Length: a compressed stream ending in "\r" is still read (the old "\nendstream" split lost it)'] = function () use ($pdfText) {
    // Find a content stream whose deflated bytes end with "\r" — the case the regex split corrupted.
    $text = "\x00P\x00a\x00y\x00a\x00b\x00l\x00e";
    for ($i = 0, $data = ''; $i < 100000; $i++) {
        $content = 'BT /F1 9 Tf 0 0 Td (' . $text . ') Tj ET % ' . $i;
        $data = gzcompress($content);
        if (str_ends_with($data, "\r")) {
            break;
        }
    }
    LiteAssert::true(str_ends_with($data, "\r"), 'found a stream ending in \r');
    $pdf = "%PDF-1.4\n1 0 obj\n<</Filter /FlateDecode /Length " . strlen($data) . ">>\nstream\n{$data}\nendstream\nendobj\n%%EOF";
    preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $old);
    LiteAssert::same(false, @gzuncompress($old[1][0]), 'the old split really loses the last byte');
    LiteAssert::contains('Payable', $pdfText($pdf), 'the /Length split reads it');
    // TJ arrays and hex strings are read too.
    $tj = gzcompress('BT [(' . "\x00A\x00b" . ') -20 <00630064>] TJ ET');
    LiteAssert::contains('Abcd', $pdfText("%PDF-1.4\n2 0 obj\n<</Length " . strlen($tj) . "\n/Filter /FlateDecode\n>>\nstream\n{$tj}\nendstream\nendobj"));
};

$tests['8. print view model: credited / payable null without view; remaining and status unchanged'] = function () use ($boot, $userWith, $invoice, $credit) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view']);
    $id = $invoice($a, '100');
    $credit($a, $id, '300');
    // Built with the same arguments FinanceModule passes (the routes in tests 6–7 cover the real wiring).
    $print = new \Modules\Finance\Services\DocumentPrintService(
        $a->container()->make(InvoiceService::class),
        $a->container()->make(\Modules\Finance\Services\ProformaService::class),
        new \Modules\Finance\CreditNoteRepository(),
        new \Modules\Finance\FinancialProfileRepository(),
        new \App\Infrastructure\Repositories\OrganizationRepository(),
        $a->container()->make(\App\Application\Services\OrganizationService::class),
        new \App\Infrastructure\Repositories\ContactRepository(),
        new \App\Infrastructure\Repositories\CompanyRepository(),
        new \App\Infrastructure\Repositories\CurrencyRepository(),
        $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
    );
    $plain = $print->invoice($user, $id);
    LiteAssert::same(null, $plain['totals']['credited']);
    LiteAssert::same(null, $plain['totals']['payable']);
    LiteAssert::same(0, bccomp('600', $plain['totals']['remaining'], 8), 'remaining net of credits');
    LiteAssert::same('partial', $plain['status']);
    $admin = $print->invoice($a->admin, $id);
    LiteAssert::same(0, bccomp('300', (string) $admin['totals']['credited'], 8));
    LiteAssert::same(0, bccomp('700', (string) $admin['totals']['payable'], 8));
    LiteAssert::same($plain['totals']['remaining'], $admin['totals']['remaining'], 'same remaining for both');
};

$tests['9. proforma print / PDF unchanged (grand total row, totals in words)'] = function () use ($boot, $h, $page, $text, $en) {
    $a = $boot();
    $pf = $a->container()->make(\Modules\Finance\Services\ProformaService::class)->create($a->admin, [
        'currency_id' => $h->currency, 'pricing_mode' => 'tax_exclusive', 'issue_date' => date('Y-m-d'),
        'items' => [['description' => 'LiteTest PB proforma', 'quantity' => '1', 'unit_price' => '500', 'discount_type' => 'none', 'discount_value' => '0']],
    ]);
    $t = $text($page($a, $a->admin, '/finance/proformas/' . (int) $pf['id'] . '/print'));
    LiteAssert::contains($en['print.grand_total'], $t);
    LiteAssert::contains($en['print.words_total'], $t);
    LiteAssert::notContains($en['print.credited'], $t);
    LiteAssert::same('%PDF', substr($page($a, $a->admin, '/finance/proformas/' . (int) $pf['id'] . '/pdf'), 0, 4));
};

$tests['10. scope: an "own"-scope user cannot open another user\'s invoice page, print or PDF'] = function () use ($boot, $invoice, $credit) {
    $a = $boot();
    $id = $invoice($a); // admin-owned; the member role has invoices.view = own
    $credit($a, $id, '300');
    $a->as($a->member);
    foreach (['', '/print', '/pdf'] as $suffix) {
        LiteAssert::true($a->outcome('GET', "/finance/invoices/{$id}{$suffix}") !== 'ok', "denied: {$suffix}");
    }
    $a->as($a->admin);
};

$tests['11. organization isolation: another organization\'s credit note on my invoice id is neither listed nor counted'] = function () use ($boot, $invoice, $credit, $page, $text, $en) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $id = $invoice($a);
    $foreign = $credit($a, $id, '999', $a->otherOrgId);
    $html = $page($a, $a->admin, "/finance/invoices/{$id}");
    LiteAssert::notContains($foreign, $html);
    LiteAssert::contains($en['payments.outstanding'] . ': 1,000', $text($html), 'outstanding untouched');
    $other = (int) $a->pdo->query("SELECT id FROM invoices WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetchColumn();
    if ($other > 0) {
        foreach (['', '/print', '/pdf'] as $suffix) {
            LiteAssert::same('notfound', $a->outcome('GET', "/finance/invoices/{$other}{$suffix}"), "foreign invoice {$suffix}");
        }
    }
};

$tests['12. all five languages: no credit-note label or detail for a user without view'] = function () use ($boot, $userWith, $invoice, $credit, $page, $text) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view']);
    $id = $invoice($a, '100');
    $number = $credit($a, $id, '300');
    try {
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $lang = require dirname(__DIR__, 2) . "/lang/{$locale}.php";
            foreach (["/finance/invoices/{$id}", "/finance/invoices/{$id}/print"] as $path) {
                $t = $text($page($a, $user, $path));
                LiteAssert::notContains($number, $t, "{$locale} {$path}");
                LiteAssert::notContains('LiteTestPBReason', $t, "{$locale} {$path}");
                foreach (['credit_notes.title', 'credit_notes.total_credited', 'credit_notes.effective_total', 'print.credited', 'print.payable'] as $key) {
                    LiteAssert::notContains($lang[$key], $t, "{$locale} {$path}: {$key}");
                }
            }
        }
    } finally {
        $a->locale('en');
    }
};

$tests['13. permissions stay independent and definitions are unchanged'] = function () use ($boot, $userWith) {
    $a = $boot();
    $engine = $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class);
    $viewOnly = $userWith($a, ['credit_notes.view']);
    $createOnly = $userWith($a, ['credit_notes.create']);
    LiteAssert::true($engine->can($viewOnly, 'credit_notes.view'));
    LiteAssert::false($engine->can($viewOnly, 'credit_notes.create'));
    LiteAssert::true($engine->can($createOnly, 'credit_notes.create'));
    LiteAssert::false($engine->can($createOnly, 'credit_notes.view'));
    LiteAssert::same('credit_notes.view', \App\Infrastructure\Authorization\Permission::CREDIT_NOTES_VIEW);
    LiteAssert::same('credit_notes.create', \App\Infrastructure\Authorization\Permission::CREDIT_NOTES_CREATE);
};

$tests['14. controller data: without view no credit list / credited / effective total is handed to the template at all'] = function () use ($boot, $userWith, $invoice, $credit) {
    $a = $boot();
    $user = $userWith($a, ['invoices.view', 'credit_notes.create']);
    $id = $invoice($a, '100');
    $credit($a, $id, '300');
    // A throw-away theme whose invoice "page" just dumps the variables the controller passed.
    $dir = sys_get_temp_dir() . '/litetest_pb_' . bin2hex(random_bytes(4));
    try {
        mkdir($dir . '/t/templates/finance/invoices', 0777, true);
        file_put_contents($dir . '/t/templates/layout.php', '<?php echo $content;');
        file_put_contents($dir . '/t/templates/finance/invoices/show.php', '<?php echo json_encode(compact("canViewCreditNotes", "creditNotes", "totalCredited", "effectiveTotal", "outstanding", "paymentStatus", "canCreditMore"));');
        $c = $a->container();
        $controller = new \Modules\Finance\Controllers\InvoiceController(
            new \App\Infrastructure\Theme\TemplateEngine(new \App\Infrastructure\Theme\ThemeLoader($dir, 't')),
            $c->make(InvoiceService::class),
            new \App\Infrastructure\Repositories\ContactRepository(),
            new \App\Infrastructure\Repositories\CurrencyRepository(),
            $c->make(\Modules\Finance\TaxProfileRepository::class),
            $c->make(\Modules\Finance\ProductRepository::class),
            $c->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
            $c->make(\Modules\Finance\Services\PaymentService::class),
            new \Modules\Finance\FinancialProfileRepository(),
            new \Modules\Finance\Services\CreditNoteService(
                new \Modules\Finance\CreditNoteRepository(),
                new \Modules\Finance\CreditNoteNumberSeriesRepository(),
                new \Modules\Finance\InvoiceRepository(),
                $c->make(\App\Infrastructure\Authorization\AuthorizationEngine::class),
                new \Modules\Finance\Services\FinancialPeriodService(new \Modules\Finance\FinancialPeriodRepository(), $c->make(\App\Infrastructure\Authorization\AuthorizationEngine::class)),
            ),
        );
        $dataFor = function (array $u) use ($a, $controller, $id): array {
            $a->as($u);
            try {
                $request = new \App\Core\Request('GET', "/finance/invoices/{$id}", [], [], [], []);
                $request->setAttribute('id', $id);
                $body = (new \ReflectionProperty(\App\Core\Response::class, 'body'))->getValue($controller->show($request));

                return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            } finally {
                $a->as($a->admin);
            }
        };
        $plain = $dataFor($user);
        $admin = $dataFor($a->admin);
    } finally {
        foreach (['/t/templates/layout.php', '/t/templates/finance/invoices/show.php'] as $file) {
            if (is_file($dir . $file)) {
                unlink($dir . $file);
            }
        }
        foreach (['/t/templates/finance/invoices', '/t/templates/finance', '/t/templates', '/t', ''] as $sub) {
            if (is_dir($dir . $sub)) {
                rmdir($dir . $sub);
            }
        }
    }
    LiteAssert::same(false, $plain['canViewCreditNotes']);
    LiteAssert::same([], $plain['creditNotes'], 'no credit-note rows');
    LiteAssert::same(null, $plain['totalCredited']);
    LiteAssert::same(null, $plain['effectiveTotal']);
    LiteAssert::same(true, $plain['canCreditMore'], 'a yes/no only');
    LiteAssert::same(0, bccomp('600', (string) $plain['outstanding'], 8), 'outstanding still net of credits');
    LiteAssert::same('partial', $plain['paymentStatus']);
    LiteAssert::same(true, $admin['canViewCreditNotes']);
    LiteAssert::same(1, count($admin['creditNotes']));
    LiteAssert::same(0, bccomp('300', (string) $admin['totalCredited'], 8));
    LiteAssert::same($plain['outstanding'], $admin['outstanding'], 'same outstanding for both');
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM credit_notes WHERE reason = 'LiteTestPBReason'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM roles WHERE `key` LIKE 'litetest_pb_%'")->fetchColumn());
};

return $tests;
