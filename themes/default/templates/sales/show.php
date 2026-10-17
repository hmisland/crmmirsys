<?php
/** @var array $deal */
/** @var array $pipeline */
/** @var array $stages */
/** @var array|null $contact */
/** @var array|null $company */
/** @var array $tasks */
/** @var array $users */
/** @var array<string, bool> $taskCan */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($deal['name']) ?></h1>
    <p class="muted">
        <?= e($deal['currency_code']) ?> <?= e(number_format((float) $deal['value'], 0)) ?>
        · <?= badge(t('deals.status.' . $deal['status']), $deal['status']) ?>
        <?= $deal['expected_close_date'] ? ' · ' . e(t('deals.expected')) . ': ' . e($deal['expected_close_date']) : '' ?>
    </p>
    <?php if ($contact !== null): ?><p><?= e(t('common.contact')) ?>: <a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a></p><?php endif; ?>
    <?php if ($company !== null): ?><p><?= e(t('common.company')) ?>: <a href="/companies/<?= e($company['id']) ?>"><?= e($company['name']) ?></a></p><?php endif; ?>

    <form method="post" action="/deals/<?= e($deal['id']) ?>/stage" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="/deals/<?= e($deal['id']) ?>">
        <label for="stage_id" style="margin:0"><?= e(t('deals.stage')) ?>:</label>
        <select id="stage_id" name="stage_id" onchange="this.form.submit()">
            <?php foreach ($stages as $stage): ?>
                <option value="<?= e($stage['id']) ?>" <?= (int) $stage['id'] === (int) $deal['stage_id'] ? 'selected' : '' ?>><?= e($stage['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="actions">
        <a href="/deals/<?= e($deal['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <form method="post" action="/deals/<?= e($deal['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    </div>
</section>

<section class="card">
    <h2><?= e(t('nav.tasks')) ?></h2>
    <ul class="item-list task-list">
        <?php $returnTo = '/deals/' . (int) $deal['id']; ?>
        <?php foreach ($tasks as $task): ?>
            <?php include $theme->templatesPath() . '/sales/_task_row.php'; ?>
        <?php endforeach; ?>
        <?php if ($tasks === []): ?><li class="item-list--empty"><?= e(t('tasks.empty')) ?></li><?php endif; ?>
    </ul>

    <?php if ($taskCan['create']): ?>
    <form method="post" action="/tasks" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="hidden" name="related_type" value="deal">
        <input type="hidden" name="related_id" value="<?= e($deal['id']) ?>">
        <input type="hidden" name="return_to" value="/deals/<?= e($deal['id']) ?>">
        <input type="text" name="title" placeholder="<?= e(t('tasks.new_placeholder')) ?>" required>
        <input type="text" class="crm-datepicker" name="due_at" placeholder="<?= e(t('tasks.due_date')) ?>">
        <select name="assigned_to_user_ids[]" multiple size="3" style="min-width:140px">
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>" <?= (int) $u['id'] === (int) CurrentUser::id() ? 'selected' : '' ?>>
                    <?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('plus') ?><?= e(t('tasks.add')) ?></button>
    </form>
    <p class="muted"><?= e(t('tasks.more_options_hint')) ?></p>
    <?php endif; ?>
</section>

<?php $panelSubjectType = 'deal'; $panelSubject = $deal; include $theme->templatesPath() . '/partials/related-panels.php'; ?>
