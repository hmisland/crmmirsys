<?php
/**
 * Edit / void one contract installment (Stage 2). Rendered only for a user
 * who may manage it (InstallmentService::forEdit()); every action re-checks
 * on the server.
 *
 * @var array $installment
 * @var string $label
 * @var string $contractTitle
 * @var string|null $contractUrl
 * @var bool $canEdit
 * @var bool $canVoid
 * @var string|null $blockedReason
 * @var string $currencyCode
 * @var array $errors
 * @var array $old
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$fieldError = fn (string $field): ?string => $errors[$field][0] ?? null;
$value = fn (string $field, $fallback) => array_key_exists($field, $old) ? (string) $old[$field] : (string) ($fallback ?? '');
$plain = fn (string $d): string => str_contains($d, '.') ? rtrim(rtrim($d, '0'), '.') : $d;
?>
<section class="card">
    <h1><?= e($label) ?></h1>
    <p class="muted">
        <?= e(t('finance.installment.contract')) ?>:
        <?php if ($contractUrl !== null): ?><a href="<?= e($contractUrl) ?>"><?= e($contractTitle) ?></a><?php else: ?><?= e($contractTitle) ?><?php endif; ?>
    </p>
    <?php if ($blockedReason !== null): ?>
        <p class="field-hint"><?= icon('info') ?> <?= e($blockedReason) ?></p>
    <?php endif; ?>
    <?php foreach (['installment', 'contract'] as $general): ?>
        <?php if ($fieldError($general) !== null): ?>
            <div class="alert alert--error"><div class="alert__body"><?= e($fieldError($general)) ?></div></div>
        <?php endif; ?>
    <?php endforeach; ?>
</section>

<?php if ($canEdit): ?>
<section class="card card--form">
    <h2><?= e(t('finance.installment.edit')) ?></h2>
    <form method="post" action="/finance/installments/<?= e($installment['id']) ?>">
        <?= Csrf::field() ?>

        <label for="due_date"><?= e(t('finance.installment.due_date')) ?></label>
        <input type="text" class="crm-datepicker" id="due_date" name="due_date" value="<?= e($value('due_date', $installment['due_date'])) ?>" required>
        <?php if ($fieldError('due_date') !== null): ?><div class="field-error"><?= e($fieldError('due_date')) ?></div><?php endif; ?>

        <label for="amount"><?= e(t('finance.installment.amount')) ?> (<span class="ltr"><?= e($currencyCode) ?></span>)</label>
        <input type="text" inputmode="decimal" id="amount" name="amount" value="<?= e($value('amount', $plain((string) $installment['amount']))) ?>" required>
        <?php if ($fieldError('amount') !== null): ?><div class="field-error"><?= e($fieldError('amount')) ?></div><?php endif; ?>

        <label for="description"><?= e(t('finance.installment.description')) ?></label>
        <input type="text" id="description" name="description" maxlength="255" value="<?= e($value('description', $installment['description'])) ?>">
        <?php if ($fieldError('description') !== null): ?><div class="field-error"><?= e($fieldError('description')) ?></div><?php endif; ?>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('finance.installment.save')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($canVoid): ?>
<section class="card card--form">
    <h2><?= e(t('finance.installment.void')) ?></h2>
    <p class="field-hint"><?= e(t('finance.installment.void_hint')) ?></p>
    <form method="post" action="/finance/installments/<?= e($installment['id']) ?>/void">
        <?= Csrf::field() ?>
        <label for="void_reason"><?= e(t('finance.installment.void_reason')) ?></label>
        <input type="text" id="void_reason" name="reason" maxlength="450" value="<?= e($value('reason', '')) ?>" required>
        <?php if ($fieldError('reason') !== null): ?><div class="field-error"><?= e($fieldError('reason')) ?></div><?php endif; ?>

        <button type="submit" class="btn btn--danger" data-confirm><?= icon('ban') ?><?= e(t('finance.installment.void')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($contractUrl !== null): ?>
    <a href="<?= e($contractUrl) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('finance.installment.back_to_contract')) ?></a>
<?php endif; ?>
