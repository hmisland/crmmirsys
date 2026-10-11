<?php
/**
 * Body of the "Project revenue" panel on a project page (Stage 3-B,
 * Modules\Finance\FinancePanels). The heading and card come from
 * partials/related-panels.php. Data: ProjectRevenueService::panelFor() —
 * only invoices this user may view, one block per invoice currency (never
 * a cross-currency total); credit figures only with credit_notes.view.
 *
 * @var array $summary per currency: gross, paid; receivable|credited|net|refund_due are null without credit_notes.view
 * @var array $documents attributed invoices this user may view (all statuses; only issued ones count)
 * @var bool $showCredits
 * @var bool $canAttach
 * @var array $candidates unattributed invoices this user may attach
 * @var array<int, bool> $detachable invoice id => may detach (drafts only)
 * @var bool $canCreateInvoice Stage 3-C: invoices.create + projects.edit on an open project
 * @var int $projectId
 * @var array<int, string> $codes currency id => code
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

$money = function ($amount, ?int $currencyId) use ($fmt, $codes): string {
    $code = $currencyId !== null ? ($codes[$currencyId] ?? null) : null;

    return \App\Infrastructure\Theme\money((string) $amount, $code, $fmt) . ($currencyId !== null && ($codes[$currencyId] ?? '') !== '' ? ' ' . $codes[$currencyId] : '');
};
?>
<?php if (!empty($canCreateInvoice)): ?>
<div class="actions">
    <a href="/finance/invoices/create?from_project=<?= e($projectId) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('invoices.new')) ?></a>
</div>
<?php endif; ?>
<dl class="fact-row">
    <?php if ($summary === []): ?>
        <div class="fact"><dt><?= icon('receipt') ?><?= e(t('finance.project.gross')) ?></dt><dd>—</dd></div>
    <?php endif; ?>
    <?php foreach ($summary as $s): ?>
        <div class="fact"><dt><?= icon('receipt') ?><?= e(t('finance.project.gross')) ?></dt><dd><?= e($money($s['gross'], $s['currency_id'])) ?></dd></div>
        <?php if ($showCredits && $s['credited'] !== null): ?>
            <div class="fact"><dt><?= icon('undo') ?><?= e(t('finance.credited')) ?></dt><dd><?= e($money($s['credited'], $s['currency_id'])) ?></dd></div>
            <div class="fact"><dt><?= icon('bar-chart') ?><?= e(t('finance.project.net_revenue')) ?></dt><dd><?= e($money($s['net'], $s['currency_id'])) ?></dd></div>
        <?php endif; ?>
        <div class="fact"><dt><?= icon('check-circle') ?><?= e(t('finance.paid')) ?></dt><dd><?= e($money($s['paid'], $s['currency_id'])) ?></dd></div>
        <?php if ($showCredits && $s['receivable'] !== null): ?>
            <div class="fact"><dt><?= icon('coins') ?><?= e(t('finance.balance')) ?></dt><dd><?= e($money($s['receivable'], $s['currency_id'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($showCredits && $s['refund_due'] !== null && bccomp((string) $s['refund_due'], '0', 18) > 0): ?>
            <div class="fact"><dt><?= icon('alert-triangle') ?><?= e(t('finance.refund_due')) ?></dt><dd><?= e($money($s['refund_due'], $s['currency_id'])) ?></dd></div>
        <?php endif; ?>
    <?php endforeach; ?>
</dl>
<p class="field-hint"><?= e(t('finance.project.hint')) ?></p>

<h3><?= e(t('invoices.title')) ?></h3>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('invoices.number')) ?></th>
        <th><?= e(t('wallet.date')) ?></th>
        <th class="num"><?= e(t('deals.value')) ?></th>
        <th><?= e(t('deals.status')) ?></th>
        <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($documents as $d): ?>
        <tr>
            <td><a href="/finance/invoices/<?= e($d['id']) ?>"><span class="ltr"><?= e($d['number']) ?></span></a></td>
            <td class="nowrap"><?= e($fmt->date($d['issue_date'])) ?></td>
            <td class="num"><?= e($money($d['amount'], $d['currency_id'])) ?></td>
            <td><?= badge(t('invoices.status.' . $d['status']), $d['status']) ?></td>
            <td>
                <?php if (!empty($detachable[$d['id']])): ?>
                    <form method="post" action="/finance/projects/<?= e($projectId) ?>/invoices/<?= e($d['id']) ?>/detach" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('x') ?><?= e(t('finance.project.detach')) ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($documents === []): ?>
        <tr><td colspan="5" class="muted"><?= e(t('finance.project.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php if ($canAttach && $candidates !== []): ?>
    <form method="post" action="/finance/projects/<?= e($projectId) ?>/invoices" class="inline-form-row">
        <?= Csrf::field() ?>
        <select name="invoice_id" aria-label="<?= e(t('finance.project.attach_choose')) ?>">
            <?php foreach ($candidates as $c): ?>
                <?php $number = ($c['custom_number'] ?? '') !== '' ? $c['custom_number'] : (!empty($c['invoice_number']) ? $c['invoice_number'] : '#' . $c['id']); ?>
                <option value="<?= e($c['id']) ?>"><?= e($number . ' — ' . $money($c['grand_total'], (int) $c['currency_id']) . ' — ' . t('invoices.status.' . $c['status'])) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--sm" data-confirm><?= icon('plus') ?><?= e(t('finance.project.attach')) ?></button>
    </form>
    <p class="field-hint"><?= e(t('finance.project.attach_hint')) ?></p>
<?php endif; ?>
