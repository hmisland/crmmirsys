<?php
/** @var array|null $ticket */
/** @var array $contacts */
/** @var array $categories */
/** @var array $users */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $ticket[$field] ?? $default);
$action = $ticket !== null ? '/support/tickets/' . $ticket['id'] : '/support/tickets';
?>
<section class="card card--form" style="max-width:600px">
    <h1><?= $ticket !== null ? e(t('tickets.edit')) : e(t('tickets.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="subject"><?= e(t('tickets.subject')) ?></label>
        <input type="text" id="subject" name="subject" value="<?= $val('subject') ?>" required autofocus>
        <?php if ($fieldError('subject')): ?><div class="field-error"><?= e($fieldError('subject')) ?></div><?php endif; ?>

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <textarea id="description" name="description" rows="4" style="width:100%;padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:inherit;"><?= $val('description') ?></textarea>

        <label for="contact_id"><?= e(t('common.contact')) ?></label>
        <select id="contact_id" name="contact_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($contacts as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['contact_id'] ?? $ticket['contact_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="category_id"><?= e(t('tickets.category')) ?></label>
        <select id="category_id" name="category_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['id']) ?>" <?= (string) ($old['category_id'] ?? $ticket['category_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="priority"><?= e(t('tickets.priority')) ?></label>
        <select id="priority" name="priority">
            <?php foreach (['low', 'medium', 'high', 'urgent'] as $p): ?>
                <option value="<?= e($p) ?>" <?= ($old['priority'] ?? $ticket['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= e(t('tickets.priority.' . $p)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="assigned_to_user_id"><?= e(t('tickets.assigned_to')) ?></label>
        <select id="assigned_to_user_id" name="assigned_to_user_id">
            <option value=""><?= e(t('tickets.unassigned')) ?></option>
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>" <?= (string) ($old['assigned_to_user_id'] ?? $ticket['assigned_to_user_id'] ?? '') === (string) $u['id'] ? 'selected' : '' ?>>
                    <?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button>
    </form>
</section>
