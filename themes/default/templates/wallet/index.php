<?php
/** @var array $wallet */
/** @var array $ledger */
/** @var array $withdrawalRequests */
/** @var array $limits */
/** @var array $gateways */
/** @var array $colleagues */
/** @var array $bankAccounts */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('wallet.my_wallet')) ?></h1>
    <p class="stat-value"><?= e(number_format((float) $wallet['balance'], 0)) ?></p>
    <p class="muted"><?= e(t('wallet.status')) ?>: <?= badge(t('wallet.wallet_status.' . $wallet['status']), $wallet['status']) ?></p>
</section>

<section class="card card--form">
    <h2><?= e(t('wallet.top_up')) ?></h2>
    <?php if ($gateways === []): ?>
        <p class="muted"><?= e(t('wallet.no_gateway')) ?></p>
    <?php else: ?>
        <form method="post" action="/wallet/topup">
            <?= Csrf::field() ?>
            <label for="amount"><?= e(t('wallet.amount')) ?></label>
            <input type="number" id="amount" name="amount" min="1" required>
            <div class="field-hint"><?= e(t('wallet.between')) ?> <?= e(number_format((float) $limits['min_topup'], 0)) ?> <?= e(t('wallet.and')) ?> <?= e(number_format((float) $limits['max_topup'], 0)) ?>.</div>

            <label for="gateway"><?= e(t('wallet.gateway')) ?></label>
            <select id="gateway" name="gateway">
                <?php foreach ($gateways as $g): ?>
                    <option value="<?= e($g->key()) ?>"><?= e($g->label()) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn--primary"><?= icon('credit-card') ?><?= e(t('wallet.pay')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card card--form">
    <h2><?= e(t('wallet.transfer_to_colleague')) ?></h2>
    <?php if ($colleagues === []): ?>
        <p class="muted"><?= e(t('wallet.no_colleagues')) ?></p>
    <?php else: ?>
        <form method="post" action="/wallet/transfer">
            <?= Csrf::field() ?>
            <label for="recipient_user_id"><?= e(t('wallet.to')) ?></label>
            <select id="recipient_user_id" name="recipient_user_id" required>
                <?php foreach ($colleagues as $c): ?>
                    <option value="<?= e($c['id']) ?>"><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="transfer_amount"><?= e(t('wallet.amount')) ?></label>
            <input type="number" id="transfer_amount" name="amount" min="1" required>

            <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('wallet.send')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card card--form">
    <h2><?= e(t('wallet.request_withdrawal')) ?></h2>

    <?php if ($bankAccounts === []): ?>
        <p class="muted">
            <?= e(t('wallet.no_bank_account')) ?> <a href="/profile"><?= e(t('wallet.add_one_on_profile')) ?></a> —
            <?= e(t('wallet.withdrawal_hint')) ?>
        </p>
    <?php else: ?>
        <form method="post" action="/wallet/withdraw">
            <?= Csrf::field() ?>
            <label for="withdraw_amount"><?= e(t('wallet.amount')) ?></label>
            <input type="number" id="withdraw_amount" name="amount" min="1" required>

            <label for="bank_account_id"><?= e(t('wallet.pay_out_to')) ?></label>
            <select id="bank_account_id" name="bank_account_id" required>
                <?php foreach ($bankAccounts as $account): ?>
                    <option value="<?= e($account['id']) ?>">
                        <?= e($account['bank_name']) ?><?= $account['account_number'] ? ' — ' . e($account['account_number']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="field-hint"><a href="/profile"><?= e(t('wallet.manage_bank_accounts')) ?></a></div>

            <button type="submit" class="btn"><?= icon('credit-card') ?><?= e(t('wallet.request')) ?></button>
        </form>
    <?php endif; ?>

    <?php if ($withdrawalRequests !== []): ?>
        <h3 style="margin-top:1rem"><?= e(t('wallet.your_requests')) ?></h3>
        <ul>
            <?php foreach ($withdrawalRequests as $req): ?>
                <li><?= e(number_format((float) $req['amount'], 0)) ?> — <?= badge(t('wallet.withdrawal_status.' . $req['status']), $req['status']) ?> <span class="muted">(<?= e($req['requested_at']) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(t('wallet.recent_activity')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.type')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('wallet.balance_after')) ?></th><th><?= e(t('contacts.description')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $entry): ?>
            <tr>
                <td><?= e($entry['created_at']) ?></td>
                <td><?= e(t('wallet.ledger_type.' . $entry['type'])) ?></td>
                <td><?= e(number_format((float) $entry['amount'], 0)) ?></td>
                <td><?= e(number_format((float) $entry['balance_after'], 0)) ?></td>
                <td class="muted"><?= e($entry['description'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($ledger === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('wallet.no_activity')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
