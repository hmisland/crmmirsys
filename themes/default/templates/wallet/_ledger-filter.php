<?php
/**
 * Ledger type filter + pagination bar, shared by the wallet page and
 * the organization wallet admin page.
 *
 * @var string $ledgerType
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$ledgerTypes = ['topup', 'withdrawal', 'transfer_in', 'transfer_out', 'transfer_fee', 'adjustment_credit', 'adjustment_debit', 'reversal', 'gift_credit', 'gift_sent'];
?>
<form method="get" class="inline-form-row filter-bar">
    <select name="type" aria-label="<?= e(t('wallet.type')) ?>">
        <option value=""><?= e(t('filters.all_types')) ?></option>
        <?php foreach ($ledgerTypes as $lt): ?>
            <option value="<?= e($lt) ?>" <?= $ledgerType === $lt ? 'selected' : '' ?>><?= e(t('wallet.ledger_type.' . $lt)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
    <?php if ($ledgerType !== ''): ?><a href="?" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
</form>

<?php include __DIR__ . '/../partials/pagination.php'; ?>
