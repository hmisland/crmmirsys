<?php
/** @var array $rules */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('followups.rules_title')) ?></h1>
    <p class="muted"><?= e(t('followups.rules_hint')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('followups.rule_event')) ?></th><th><?= e(t('deals.status')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
            <tr>
                <td><?= e($r['name']) ?><?php if (!empty($r['description'])): ?><div class="muted"><?= e($r['description']) ?></div><?php endif; ?></td>
                <td class="muted"><?= e($r['event_type']) ?></td>
                <td><?= $r['enabled'] ? e(t('products.active')) : e(t('products.inactive')) ?></td>
                <td>
                    <form method="post" action="/followups/rules/<?= e($r['id']) ?>/toggle" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= $r['enabled'] ? e(t('followups.disable_action')) : e(t('followups.enable_action')) ?></button>
                    </form>
                    <form method="post" action="/followups/rules/<?= e($r['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rules === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('followups.rules_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('followups.add_rule')) ?></h2>
    <form method="post" action="/followups/rules">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" required>

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <input type="text" id="description" name="description">

        <label for="event_type"><?= e(t('followups.rule_event')) ?></label>
        <select id="event_type" name="event_type">
            <option value="DealStageChanged">DealStageChanged</option>
        </select>
        <div class="field-hint"><?= e(t('followups.rule_event_hint')) ?></div>

        <label for="conditions_json"><?= e(t('followups.rule_conditions')) ?></label>
        <textarea id="conditions_json" name="conditions_json" rows="2" placeholder='{"is_won": true}' style="width:100%;padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:monospace;"></textarea>
        <div class="field-hint"><?= e(t('followups.rule_conditions_hint')) ?></div>

        <label for="action_configuration_json"><?= e(t('followups.rule_action')) ?></label>
        <textarea id="action_configuration_json" name="action_configuration_json" rows="4" placeholder='{"title": "Follow up on won deal", "due_in_hours": 24, "assign_to": "deal_owner", "priority": "high", "reminder_minutes_before": 30}' style="width:100%;padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:monospace;" required></textarea>
        <div class="field-hint"><?= e(t('followups.rule_action_hint')) ?></div>

        <label for="priority"><?= e(t('followups.rule_priority')) ?></label>
        <input type="number" id="priority" name="priority" value="0">

        <label style="display:inline-flex;align-items:center;gap:0.4rem;font-weight:normal;margin-top:0.5rem;">
            <input type="checkbox" name="enabled" value="1" checked> <?= e(t('products.is_active')) ?>
        </label>

        <p style="margin-top:1rem"><button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button></p>
    </form>
</section>
