<?php
/** @var array $pendingWithdrawals */
/** @var array $transactions */
/** @var array|null $orgSettings */
/** @var array $platformLimits */
/** @var array $users */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('wallet.admin_title')) ?></h1>
</section>

<section class="card">
    <h2><?= e(t('wallet.pending_withdrawals')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.user')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('wallet.destination')) ?></th><th><?= e(t('wallet.requested')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pendingWithdrawals as $req): ?>
            <tr>
                <td><?= e($req['user_name'] ?? ('#' . $req['user_id'])) ?></td>
                <td><?= e(number_format((float) $req['amount'], 0)) ?></td>
                <td class="muted"><?= e($req['destination_info']) ?></td>
                <td><?= e(local_time($req['requested_at'])) ?></td>
                <td>
                    <form method="post" action="/org/wallet/withdrawals/<?= e($req['id']) ?>/approve" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= icon('check') ?><?= e(t('wallet.approve')) ?></button>
                    </form>
                    <form method="post" action="/org/wallet/withdrawals/<?= e($req['id']) ?>/reject" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= icon('x-circle') ?><?= e(t('wallet.reject')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($pendingWithdrawals === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('wallet.nothing_pending')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($canGift ?? false): ?>
<section class="card card--form">
    <h2><?= e(t('wallet_gift.redeem_title')) ?></h2>
    <p class="muted"><?= e(t('wallet_gift.redeem_hint')) ?></p>
    <form method="post" action="/org/wallet/gift/redeem">
        <?= Csrf::field() ?>
        <label for="gift_code"><?= e(t('wallet_gift.code')) ?></label>
        <input type="text" id="gift_code" name="code" required dir="ltr" autocomplete="off" placeholder="GIFT-XXXX-XXXX-XXXX" class="field-md">
        <button type="submit" class="btn btn--primary"><?= icon('check') ?><?= e(t('wallet_gift.redeem')) ?></button>
    </form>
</section>

<section class="card card--form">
    <h2><?= e(t('wallet_gift.send_title')) ?></h2>
    <p class="muted"><?= e(t('wallet_gift.send_hint')) ?></p>
    <form method="post" action="/org/wallet/gift/send">
        <?= Csrf::field() ?>
        <label for="gift_recipient"><?= e(t('wallet_gift.recipient')) ?></label>
        <select id="gift_recipient" name="recipient_user_id" required>
            <option value=""><?= e(t('wallet_gift.choose_recipient')) ?></option>
            <?php foreach ($users as $u): ?>
                <?php if ((int) $u['id'] === (int) \App\Infrastructure\Security\CurrentUser::id() || ($u['status'] ?? 'active') !== 'active') { continue; } ?>
                <option value="<?= e($u['id']) ?>"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) . ' — ' . $u['email']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="gift_amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="gift_amount" name="amount" min="1" step="0.001" required>
        <label for="gift_note"><?= e(t('wallet_gift.note')) ?></label>
        <input type="text" id="gift_note" name="note" maxlength="200">
        <button type="submit" class="btn btn--primary" data-confirm="<?= e(t('wallet_gift.send_confirm')) ?>"><?= icon('send') ?><?= e(t('wallet_gift.send')) ?></button>
    </form>
</section>
<?php endif; ?>

<section class="card card--form">
    <h2><?= e(t('wallet.manual_adjustment')) ?></h2>
    <form method="post" action="/org/wallet/adjust">
        <?= Csrf::field() ?>
        <label for="user_id"><?= e(t('wallet.member')) ?></label>
        <select id="user_id" name="user_id" required>
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>"><?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="direction"><?= e(t('wallet.direction')) ?></label>
        <select id="direction" name="direction">
            <option value="credit"><?= e(t('wallet.credit_option')) ?></option>
            <option value="debit"><?= e(t('wallet.debit_option')) ?></option>
        </select>

        <label for="adjust_amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="adjust_amount" name="amount" min="1" required>

        <label for="reason"><?= e(t('wallet.reason')) ?></label>
        <input type="text" id="reason" name="reason" required>

        <button type="submit" class="btn btn--primary"><?= icon('check') ?><?= e(t('wallet.apply')) ?></button>
    </form>
</section>

<section class="card card--form">
    <h2><?= e(t('wallet.org_settings_title')) ?></h2>
    <p class="muted">
        <?= e(t('wallet.platform_cap')) ?>: <?= e(number_format((float) $platformLimits['min_topup'], 0)) ?> – <?= e(number_format((float) $platformLimits['max_topup'], 0)) ?>.
        <?= e(t('wallet.platform_cap_hint')) ?>
    </p>
    <form method="post" action="/org/wallet/settings">
        <?= Csrf::field() ?>

        <label for="min_topup"><?= e(t('wallet.min_topup')) ?></label>
        <input type="number" id="min_topup" name="min_topup" value="<?= e($orgSettings['min_topup'] ?? $platformLimits['min_topup']) ?>">

        <label for="max_topup"><?= e(t('wallet.max_topup')) ?></label>
        <input type="number" id="max_topup" name="max_topup" value="<?= e($orgSettings['max_topup'] ?? $platformLimits['max_topup']) ?>">

        <label for="min_withdrawal"><?= e(t('wallet.min_withdrawal')) ?></label>
        <input type="number" id="min_withdrawal" name="min_withdrawal" value="<?= e($orgSettings['min_withdrawal'] ?? '10000') ?>">

        <label for="max_withdrawal"><?= e(t('wallet.max_withdrawal')) ?></label>
        <input type="number" id="max_withdrawal" name="max_withdrawal" value="<?= e($orgSettings['max_withdrawal'] ?? '500000000') ?>">

        <label for="transfer_fee_fixed"><?= e(t('wallet.transfer_fee_fixed')) ?></label>
        <input type="number" id="transfer_fee_fixed" name="transfer_fee_fixed" value="<?= e($orgSettings['transfer_fee_fixed'] ?? '0') ?>">

        <label for="transfer_fee_percent"><?= e(t('wallet.transfer_fee_percent')) ?></label>
        <input type="number" id="transfer_fee_percent" name="transfer_fee_percent" min="0" max="100" step="0.01" value="<?= e($orgSettings['transfer_fee_percent'] ?? '0') ?>">

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('wallet.org_transactions')) ?></h2>
    <?php include __DIR__ . '/_ledger-filter.php'; ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.user')) ?></th><th><?= e(t('wallet.type')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('contacts.description')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
            <tr>
                <td><?= e(local_time($tx['created_at'])) ?></td>
                <td><?= e($tx['owner_name'] ?? '') ?></td>
                <td><?= e(t('wallet.ledger_type.' . $tx['type'])) ?></td>
                <td><?= e(number_format((float) $tx['amount'], 0)) ?></td>
                <td class="muted"><?= e($tx['description'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($transactions === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('wallet.no_activity')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
