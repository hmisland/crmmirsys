<?php
/** @var array $user */
/** @var array $errors */
/** @var array|null $notificationChannels */
/** @var array $bankAccounts */
/** @var array $banks */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<section class="card card--form">
    <h1><?= e(t('profile.title')) ?></h1>

    <p class="muted">
        <?= e($user['email']) ?>
        <?= $user['email_verified_at'] !== null ? '· ' . e(t('profile.verified')) : '· ' . e(t('profile.not_verified_yet')) ?>
    </p>

    <form method="post" action="/profile">
        <?= Csrf::field() ?>

        <label for="first_name"><?= e(t('auth.first_name')) ?></label>
        <input type="text" id="first_name" name="first_name" value="<?= e($user['first_name'] ?? '') ?>" required>
        <?php if ($fieldError('first_name')): ?><div class="field-error"><?= e($fieldError('first_name')) ?></div><?php endif; ?>

        <label for="last_name"><?= e(t('auth.last_name')) ?></label>
        <input type="text" id="last_name" name="last_name" value="<?= e($user['last_name'] ?? '') ?>">

        <label for="mobile_phone"><?= e(t('profile.mobile_phone')) ?></label>
        <input type="text" id="mobile_phone" name="mobile_phone" value="<?= e($user['mobile_phone'] ?? '') ?>">
        <div class="field-hint"><?= e(t('profile.mobile_phone_hint')) ?></div>

        <label for="national_id"><?= e(t('profile.national_id')) ?></label>
        <input type="text" id="national_id" name="national_id" value="<?= e($user['national_id'] ?? '') ?>">

        <label for="language"><?= e(t('nav.language')) ?></label>
        <select id="language" name="language">
            <option value="en" <?= ($user['language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
            <option value="fa" <?= ($user['language'] ?? '') === 'fa' ? 'selected' : '' ?>>فارسی (Persian)</option>
            <option value="ar" <?= ($user['language'] ?? '') === 'ar' ? 'selected' : '' ?>>العربية (Arabic)</option>
            <option value="tr" <?= ($user['language'] ?? '') === 'tr' ? 'selected' : '' ?>>Türkçe (Turkish)</option>
            <option value="fr" <?= ($user['language'] ?? '') === 'fr' ? 'selected' : '' ?>>Français (French)</option>
        </select>

        <label for="timezone"><?= e(t('profile.timezone')) ?></label>
        <input type="text" id="timezone" name="timezone" value="<?= e($user['timezone'] ?? 'UTC') ?>">

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('profile.save_changes')) ?></button>
    </form>
</section>

<?php if ($notificationChannels !== null): ?>
<section class="card card--form">
    <h2><?= e(t('profile.notification_channels')) ?></h2>
    <p class="muted"><?= e(t('profile.notification_channels_hint')) ?></p>

    <form method="post" action="/notifications/settings">
        <?= Csrf::field() ?>

        <label for="bale_chat_id"><?= e(t('profile.bale_chat_id')) ?></label>
        <input type="text" id="bale_chat_id" name="bale_chat_id" value="<?= e($notificationChannels['bale_chat_id'] ?? '') ?>">
        <div class="field-hint"><?= e(t('profile.bale_chat_id_hint')) ?></div>

        <label for="telegram_chat_id"><?= e(t('profile.telegram_chat_id')) ?></label>
        <input type="text" id="telegram_chat_id" name="telegram_chat_id" value="<?= e($notificationChannels['telegram_chat_id'] ?? '') ?>">
        <div class="field-hint"><?= e(t('profile.telegram_chat_id_hint')) ?></div>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('profile.save_channels')) ?></button>
    </form>

    <form method="post" action="/notifications/settings/test-bale" style="margin-top:0.75rem">
        <?= Csrf::field() ?>
        <input type="hidden" name="bale_chat_id" value="<?= e($notificationChannels['bale_chat_id'] ?? '') ?>">
        <button type="submit" class="btn"><?= icon('send') ?><?= e(t('profile.send_test_bale')) ?></button>
        <div class="field-hint"><?= e(t('profile.send_test_bale_hint')) ?></div>
    </form>
</section>
<?php endif; ?>

<?php if ($user['organization_id'] !== null): ?>
<section class="card">
    <h2><?= e(t('profile.bank_accounts')) ?></h2>
    <p class="muted">
        <?= e(t('profile.bank_accounts_notice_fa')) ?>
        <?= e(t('profile.bank_accounts_notice_en')) ?>
    </p>

    <?php if ($bankAccounts !== []): ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th><?= e(t('wallet.banks')) ?></th><th><?= e(t('profile.account_number')) ?></th><th><?= e(t('profile.sheba')) ?></th><th><?= e(t('profile.card_number')) ?></th><th><?= e(t('profile.branch')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($bankAccounts as $account): ?>
                <tr>
                    <td><?= e($account['bank_name']) ?></td>
                    <td><?= e($account['account_number'] ?? '—') ?></td>
                    <td><?= $account['sheba_number'] ? 'IR' . e($account['sheba_number']) : '—' ?></td>
                    <td><?= e($account['card_number'] ?? '—') ?></td>
                    <td><?= e($account['branch_code'] ?? '—') ?></td>
                    <td>
                        <form method="post" action="/profile/bank-accounts/<?= e($account['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted"><?= e(t('profile.no_bank_accounts_yet')) ?></p>
    <?php endif; ?>

    <div class="card--form" style="max-width:500px;margin-top:1rem">
    <form method="post" action="/profile/bank-accounts">
        <?= Csrf::field() ?>

        <label for="bank_id"><?= e(t('profile.bank')) ?></label>
        <select id="bank_id" name="bank_id" required>
            <option value=""><?= e(t('profile.choose')) ?></option>
            <?php foreach ($banks as $bank): ?>
                <option value="<?= e($bank['id']) ?>"><?= e($bank['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="account_number"><?= e(t('profile.account_number')) ?></label>
        <input type="text" id="account_number" name="account_number">

        <label for="sheba_number"><?= e(t('profile.sheba_field')) ?></label>
        <input type="text" id="sheba_number" name="sheba_number" placeholder="24 digits, no IR" maxlength="24">

        <label for="card_number"><?= e(t('profile.card_number')) ?></label>
        <input type="text" id="card_number" name="card_number" placeholder="XXXX XXXX XXXX XXXX" maxlength="19">

        <label for="branch_code"><?= e(t('profile.branch')) ?></label>
        <input type="text" id="branch_code" name="branch_code">

        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('profile.add_bank_account')) ?></button>
    </form>
    </div>
</section>
<?php endif; ?>
