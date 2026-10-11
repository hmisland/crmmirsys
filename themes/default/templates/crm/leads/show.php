<?php
/** @var array $lead */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? ''))) ?></h1>
    <p class="muted">
        <?= e($lead['email'] ?? '') ?>
        <?= $lead['phone'] ? ' · ' . e($lead['phone']) : '' ?>
        <?= $lead['company_name'] ? ' · ' . e($lead['company_name']) : '' ?>
    </p>
    <p><?= e(t('leads.status')) ?>: <strong><?= badge(t('leads.status.' . $lead['status']), $lead['status']) ?></strong> · <?= e(t('leads.score')) ?>: <?= e($lead['score']) ?></p>
    <?php if (!empty($lead['description'])): ?>
        <p><?= nl2br(e($lead['description'])) ?></p>
    <?php endif; ?>

    <div class="actions">
        <?php if ($lead['status'] !== 'converted'): ?>
            <form method="post" action="/leads/<?= e($lead['id']) ?>/convert" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary"><?= icon('arrow-left-right') ?><?= e(t('leads.convert_to_contact')) ?></button>
            </form>
        <?php else: ?>
            <a href="/contacts/<?= e($lead['converted_contact_id']) ?>" class="btn btn--primary"><?= icon('eye') ?><?= e(t('leads.view_converted_contact')) ?></a>
        <?php endif; ?>
        <a href="/leads/<?= e($lead['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <?php if ($canCreateFollowUp ?? false): ?>
            <a href="/followups/create?related_type=lead&amp;related_id=<?= e($lead['id']) ?>" class="btn"><?= icon('calendar-check') ?><?= e(t('followups.new')) ?></a>
        <?php endif; ?>
        <form method="post" action="/leads/<?= e($lead['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    </div>
</section>

<?php
$panelSubjectType = 'lead';
$panelSubject = $lead;
include $theme->templatesPath() . '/partials/related-panels.php';

$type = 'lead';
$id = (int) $lead['id'];
include $theme->templatesPath() . '/crm/_record_support.php';
?>
