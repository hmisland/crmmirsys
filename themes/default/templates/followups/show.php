<?php
/** @var array $followUp */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isOpen = in_array($followUp['status'], ['pending', 'scheduled', 'in_progress'], true);
$isOverdue = $isOpen && $followUp['due_at'] !== null && $followUp['due_at'] < date('Y-m-d H:i:s');
?>
<section class="card">
    <h1><?= e($followUp['title']) ?></h1>
    <p class="muted">
        <?= e(t('followups.type')) ?>: <?= e(t('followups.type.' . $followUp['type'])) ?>
        · <?= e(t('followups.priority')) ?>: <?= e(t('followups.priority.' . $followUp['priority'])) ?>
        · <?= e(t('deals.status')) ?>: <?= badge(t('followups.status.' . $followUp['status']), $followUp['status']) ?>
        <?php if ($isOverdue): ?> · <strong class="text-danger"><?= e(t('followups.overdue')) ?></strong><?php endif; ?>
    </p>
    <?php if ($followUp['due_at'] !== null): ?>
        <p><?= e(t('followups.due_at')) ?>: <?= e($followUp['due_at']) ?> (<?= e($followUp['timezone']) ?>)</p>
    <?php endif; ?>
    <?php if (!empty($followUp['description'])): ?>
        <p><?= nl2br(e($followUp['description'])) ?></p>
    <?php endif; ?>
    <?php if ($followUp['is_automated']): ?>
        <p class="muted"><?= e(t('followups.created_by_automation')) ?></p>
    <?php endif; ?>

    <div class="actions">
        <?php if ($isOpen): ?>
            <a href="/followups/<?= e($followUp['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
            <form method="post" action="/followups/<?= e($followUp['id']) ?>/complete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary"><?= icon('check') ?><?= e(t('followups.complete_action')) ?></button>
            </form>
            <form method="post" action="/followups/<?= e($followUp['id']) ?>/cancel" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button"><?= icon('ban') ?><?= e(t('followups.cancel_action')) ?></button>
            </form>
        <?php endif; ?>
        <form method="post" action="/followups/<?= e($followUp['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    </div>
</section>

<?php if ($isOpen): ?>
<section class="card card--form">
    <h2><?= e(t('followups.reschedule_title')) ?></h2>
    <form method="post" action="/followups/<?= e($followUp['id']) ?>/reschedule">
        <?= Csrf::field() ?>
        <label for="due_at"><?= e(t('followups.due_at')) ?></label>
        <input type="text" class="crm-datepicker" id="due_at" name="due_at" value="<?= e($followUp['due_at'] ?? '') ?>" required>
        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= icon('calendar') ?><?= e(t('followups.reschedule_action')) ?></button>
    </form>
</section>
<?php endif; ?>
