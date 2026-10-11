<?php
/**
 * Project expense budget (Stage 5): one row per expense category × currency,
 * amount ≥ 0 (zero allowed). Saving replaces the whole budget; blank rows are
 * ignored, so clearing every row removes the budget. Every value is
 * re-validated on the server (BudgetService).
 *
 * @var array $project
 * @var string|null $projectUrl
 * @var array<int, array{category: string, currency_id: string, amount: string, notes: string}> $rows
 * @var array $errors keyed "lines.{i}.{field}" or "budget"
 * @var array<int, string> $currencies currency id => code
 * @var string[] $categories
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$fieldError = fn (int $i, string $field): ?string => $errors["lines.{$i}.{$field}"][0] ?? null;
?>
<section class="card card--form">
    <h1><?= e(t('finance.budget.edit')) ?></h1>
    <p>
        <?= icon('kanban') ?> <?= e(t('finance.budget.for_project')) ?>
        <?php if ($projectUrl !== null): ?><a href="<?= e($projectUrl) ?>"><?= e($project['name'] ?? '') ?></a><?php else: ?><?= e($project['name'] ?? '') ?><?php endif; ?>
    </p>
    <?php if ($errors !== []): ?>
        <div class="alert alert--error"><div class="alert__body"><?= e(t('finance.budget.error.fix_rows')) ?></div></div>
    <?php endif; ?>

    <form method="post" action="/finance/projects/<?= e($project['id']) ?>/budget">
        <?= Csrf::field() ?>
        <div class="table-responsive">
        <table class="table table--compact">
            <thead><tr>
                <th><?= e(t('finance.budget.category')) ?></th>
                <th><?= e(t('finance.budget.currency')) ?></th>
                <th><?= e(t('finance.budget.amount')) ?></th>
                <th><?= e(t('finance.budget.notes')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td>
                        <select name="lines[<?= e($i) ?>][category]" aria-label="<?= e(t('finance.budget.category')) ?>">
                            <option value=""></option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= e($c) ?>" <?= $row['category'] === $c ? 'selected' : '' ?>><?= e(t('finance.expense.category.' . $c)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($fieldError($i, 'category') !== null): ?><div class="field-error"><?= e($fieldError($i, 'category')) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <select name="lines[<?= e($i) ?>][currency_id]" aria-label="<?= e(t('finance.budget.currency')) ?>">
                            <option value=""></option>
                            <?php foreach ($currencies as $id => $code): ?>
                                <option value="<?= e($id) ?>" <?= $row['currency_id'] === (string) $id ? 'selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($fieldError($i, 'currency_id') !== null): ?><div class="field-error"><?= e($fieldError($i, 'currency_id')) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <input type="text" inputmode="decimal" name="lines[<?= e($i) ?>][amount]" value="<?= e($row['amount']) ?>" aria-label="<?= e(t('finance.budget.amount')) ?>">
                        <?php if ($fieldError($i, 'amount') !== null): ?><div class="field-error"><?= e($fieldError($i, 'amount')) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <input type="text" name="lines[<?= e($i) ?>][notes]" maxlength="255" value="<?= e($row['notes']) ?>" aria-label="<?= e(t('finance.budget.notes')) ?>">
                        <?php if ($fieldError($i, 'notes') !== null): ?><div class="field-error"><?= e($fieldError($i, 'notes')) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="field-hint"><?= e(t('finance.budget.form_hint')) ?></div>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('finance.budget.save')) ?></button>
        <?php if ($projectUrl !== null): ?>
            <a href="<?= e($projectUrl) ?>" class="btn"><?= icon('arrow-left') ?><?= e(t('finance.budget.back')) ?></a>
        <?php endif; ?>
    </form>
</section>
