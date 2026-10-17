<?php
/**
 * Create / edit an expense (Phase 4 + Stage 5.1). Every value is re-validated
 * on the server.
 *
 * Project (financial allocation): "New expense" from a project (D-P1) keeps
 * the project fixed; otherwise an optional selector lists only the projects
 * the server would accept (projects.edit, not cancelled). On edit, "No
 * project" removes it (E7 checks run on the server).
 * Contract (Stage 5.1): an optional REFERENCE only — it never affects any
 * figure; the help note under the field says so.
 * Receipts are added on the expense page after saving (E6).
 *
 * @var array|null $expense
 * @var array $errors
 * @var array $old
 * @var array{id: int, label: ?string, url: ?string}|null $project
 * @var bool $projectFixed
 * @var array<int, string>|null $projectOptions null = selector not shown (Projects not available)
 * @var array{id: int, label: ?string, url: ?string}|null $contract
 * @var array<int, string>|null $contractOptions null = selector not shown (Contracts not available)
 * @var array $contacts
 * @var array $companies
 * @var array $currencies
 * @var int|null $defaultCurrencyId
 */

use App\Infrastructure\Security\Csrf;
use Modules\Finance\SubmissionKeyRepository;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$isEdit = $expense !== null;
$val = function (string $field, $default = '') use ($old, $expense): string {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }
    if ($expense !== null && array_key_exists($field, $expense) && $expense[$field] !== null) {
        $v = (string) $expense[$field];

        return $field === 'amount' && str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    return (string) $default;
};
$fieldError = fn (string $field): ?string => $errors[$field][0] ?? null;
$currencyId = $val('currency_id', $defaultCurrencyId ?? '');
// The current value stays selectable even when it is not among the choices (e.g. no projects.edit on it).
$withCurrent = function (?array $options, ?array $current, string $unavailableKey): array {
    $options ??= [];
    if ($current !== null && !isset($options[$current['id']])) {
        $options = [$current['id'] => $current['label'] ?? ('#' . $current['id'] . ' — ' . t($unavailableKey))] + $options;
    }

    return $options;
};
$selectedProject = $project !== null ? (string) $project['id'] : '';
$selectedContract = $contract !== null ? (string) $contract['id'] : '';
?>
<section class="card card--form">
    <h1><?= e(t($isEdit ? 'finance.expense.edit' : 'finance.expense.new')) ?></h1>
    <?php foreach (['expense', 'project'] as $general): ?>
        <?php if ($fieldError($general) !== null): ?>
            <div class="alert alert--error"><div class="alert__body"><?= e($fieldError($general)) ?></div></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <form method="post" action="<?= e($isEdit ? '/finance/expenses/' . $expense['id'] : '/finance/expenses') ?>">
        <?= Csrf::field() ?>
        <?php if (!$isEdit): ?>
            <?php // Stage 5.2 (L1): one key per form; kept when the form is shown again after an error ?>
            <input type="hidden" name="submission_key" value="<?= e(SubmissionKeyRepository::keyFrom($old) ?? SubmissionKeyRepository::newKey()) ?>">
        <?php endif; ?>

        <?php if ($projectFixed && $project !== null): ?>
            <div class="alert alert--info"><div class="alert__body">
                <?= icon('kanban') ?> <?= e(t('finance.expense.for_project')) ?>
                <?php if ($project['url'] !== null): ?><a href="<?= e($project['url']) ?>"><?= e((string) $project['label']) ?></a><?php else: ?><?= e($project['label'] ?? t('finance.expense.project_unavailable')) ?><?php endif; ?>
            </div></div>
            <input type="hidden" name="project_id" value="<?= e($project['id']) ?>">
            <input type="hidden" name="project_fixed" value="1">
        <?php endif; ?>

        <label for="amount"><?= e(t('finance.expense.amount')) ?></label>
        <input type="text" inputmode="decimal" id="amount" name="amount" value="<?= e($val('amount')) ?>" required>
        <div class="field-hint"><?= e(t('finance.expense.amount_hint')) ?></div>
        <?php if ($fieldError('amount') !== null): ?><div class="field-error"><?= e($fieldError('amount')) ?></div><?php endif; ?>

        <label for="currency_id"><?= e(t('finance.expense.currency')) ?></label>
        <select id="currency_id" name="currency_id" required>
            <?php foreach ($currencies as $cur): ?>
                <option value="<?= e($cur['id']) ?>" <?= (string) $currencyId === (string) $cur['id'] ? 'selected' : '' ?>><?= e($cur['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('currency_id') !== null): ?><div class="field-error"><?= e($fieldError('currency_id')) ?></div><?php endif; ?>

        <label for="category"><?= e(t('finance.expense.category')) ?></label>
        <select id="category" name="category" required>
            <?php foreach (\Modules\Finance\Services\ExpenseService::CATEGORIES as $c): ?>
                <option value="<?= e($c) ?>" <?= $val('category') === $c ? 'selected' : '' ?>><?= e(t('finance.expense.category.' . $c)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('category') !== null): ?><div class="field-error"><?= e($fieldError('category')) ?></div><?php endif; ?>

        <label for="expense_date"><?= e(t('finance.expense.date')) ?></label>
        <input type="text" class="crm-datepicker" id="expense_date" name="expense_date" value="<?= e($val('expense_date', local_today())) ?>" required>
        <?php if ($fieldError('expense_date') !== null): ?><div class="field-error"><?= e($fieldError('expense_date')) ?></div><?php endif; ?>

        <label for="payee_contact_id"><?= e(t('finance.expense.payee_contact')) ?></label>
        <select id="payee_contact_id" name="payee_contact_id">
            <option value=""><?= e(t('finance.expense.no_payee')) ?></option>
            <?php foreach ($contacts as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= $val('payee_contact_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('payee_contact_id') !== null): ?><div class="field-error"><?= e($fieldError('payee_contact_id')) ?></div><?php endif; ?>

        <label for="payee_company_id"><?= e(t('finance.expense.payee_company')) ?></label>
        <select id="payee_company_id" name="payee_company_id">
            <option value=""><?= e(t('finance.expense.no_payee')) ?></option>
            <?php foreach ($companies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= $val('payee_company_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="field-hint"><?= e(t('finance.expense.payee_hint')) ?></div>
        <?php if ($fieldError('payee_company_id') !== null): ?><div class="field-error"><?= e($fieldError('payee_company_id')) ?></div><?php endif; ?>

        <?php if (!$projectFixed && $projectOptions !== null): ?>
            <label for="project_id"><?= e(t('finance.expense.allocate_project')) ?></label>
            <select id="project_id" name="project_id">
                <option value=""><?= e(t('finance.expense.no_project')) ?></option>
                <?php foreach ($withCurrent($projectOptions, $project, 'finance.expense.project_unavailable') as $id => $label): ?>
                    <option value="<?= e($id) ?>" <?= $selectedProject === (string) $id ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="field-hint"><?= e(t('finance.expense.allocate_project_hint')) ?></div>
            <?php if ($fieldError('project_id') !== null): ?><div class="field-error"><?= e($fieldError('project_id')) ?></div><?php endif; ?>
        <?php elseif (!$projectFixed && $project !== null): ?>
            <div class="alert alert--info"><div class="alert__body">
                <?= icon('kanban') ?> <?= e(t('finance.expense.for_project')) ?> <?= e($project['label'] ?? t('finance.expense.project_unavailable')) ?>
            </div></div>
        <?php elseif ($projectFixed && $fieldError('project_id') !== null): ?>
            <div class="field-error"><?= e($fieldError('project_id')) ?></div>
        <?php endif; ?>

        <?php if ($contractOptions !== null): ?>
            <label for="contract_id"><?= e(t('finance.expense.allocate_contract')) ?></label>
            <select id="contract_id" name="contract_id" aria-describedby="contract_note">
                <option value=""><?= e(t('finance.expense.no_contract')) ?></option>
                <?php foreach ($withCurrent($contractOptions, $contract, 'finance.expense.contract_unavailable') as $id => $label): ?>
                    <option value="<?= e($id) ?>" <?= $selectedContract === (string) $id ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="field-hint" id="contract_note"><?= icon('info') ?> <?= e(t('finance.expense.contract_note')) ?></div>
            <?php if ($fieldError('contract_id') !== null): ?><div class="field-error"><?= e($fieldError('contract_id')) ?></div><?php endif; ?>
        <?php endif; ?>

        <label for="description"><?= e(t('finance.expense.description')) ?></label>
        <input type="text" id="description" name="description" maxlength="255" value="<?= e($val('description')) ?>" required>
        <?php if ($fieldError('description') !== null): ?><div class="field-error"><?= e($fieldError('description')) ?></div><?php endif; ?>

        <label for="reference_number"><?= e(t('finance.expense.reference')) ?></label>
        <input type="text" id="reference_number" name="reference_number" maxlength="100" value="<?= e($val('reference_number')) ?>">
        <?php if ($fieldError('reference_number') !== null): ?><div class="field-error"><?= e($fieldError('reference_number')) ?></div><?php endif; ?>

        <?php if (!$isEdit): ?>
            <div class="field-hint"><?= icon('paperclip') ?> <?= e(t('finance.expense.receipts_after_save')) ?></div>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('finance.expense.save')) ?></button>
    </form>
</section>
