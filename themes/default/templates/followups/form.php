<?php
/** @var array|null $followUp */
/** @var array $users */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $followUp[$field] ?? $default);
$action = $followUp !== null ? '/followups/' . $followUp['id'] : '/followups';
?>
<section class="card card--form">
    <h1><?= $followUp !== null ? e(t('followups.edit')) : e(t('followups.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="title"><?= e(t('common.name')) ?></label>
        <input type="text" id="title" name="title" value="<?= $val('title') ?>" required autofocus>
        <?php if ($fieldError('title')): ?><div class="field-error"><?= e($fieldError('title')) ?></div><?php endif; ?>

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <textarea id="description" name="description" rows="3"><?= $val('description') ?></textarea>

        <label for="type"><?= e(t('followups.type')) ?></label>
        <select id="type" name="type">
            <?php foreach (['call', 'email', 'message', 'meeting', 'task', 'reminder', 'note', 'custom'] as $type): ?>
                <option value="<?= e($type) ?>" <?= ($old['type'] ?? $followUp['type'] ?? 'task') === $type ? 'selected' : '' ?>><?= e(t('followups.type.' . $type)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="priority"><?= e(t('followups.priority')) ?></label>
        <select id="priority" name="priority">
            <?php foreach (['low', 'normal', 'high', 'urgent'] as $priority): ?>
                <option value="<?= e($priority) ?>" <?= ($old['priority'] ?? $followUp['priority'] ?? 'normal') === $priority ? 'selected' : '' ?>><?= e(t('followups.priority.' . $priority)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="assignee_user_id"><?= e(t('followups.assignee')) ?></label>
        <select id="assignee_user_id" name="assignee_user_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>" <?= (string) ($old['assignee_user_id'] ?? $followUp['assignee_user_id'] ?? '') === (string) $u['id'] ? 'selected' : '' ?>><?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="due_at"><?= e(t('followups.due_at')) ?></label>
        <input type="text" class="crm-datepicker" id="due_at" name="due_at" value="<?= $val('due_at') ?>">

        <p style="margin-top:1rem"><button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button></p>
    </form>
</section>
