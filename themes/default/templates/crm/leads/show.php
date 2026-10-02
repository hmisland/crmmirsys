<?php
/** @var array $lead */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? ''))) ?></h1>
    <p class="muted">
        <?= e($lead['email'] ?? '') ?>
        <?= $lead['phone'] ? ' · ' . e($lead['phone']) : '' ?>
        <?= $lead['company_name'] ? ' · ' . e($lead['company_name']) : '' ?>
    </p>
    <p><?= e(t('leads.status')) ?>: <strong><?= e(t('leads.status.' . $lead['status'])) ?></strong> · <?= e(t('leads.score')) ?>: <?= e($lead['score']) ?></p>

    <p>
        <?php if ($lead['status'] !== 'converted'): ?>
            <form method="post" action="/leads/<?= e($lead['id']) ?>/convert" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary"><?= e(t('leads.convert_to_contact')) ?></button>
            </form>
        <?php else: ?>
            <a href="/contacts/<?= e($lead['converted_contact_id']) ?>" class="btn btn--primary"><?= e(t('leads.view_converted_contact')) ?></a>
        <?php endif; ?>
        <a href="/leads/<?= e($lead['id']) ?>/edit" class="btn"><?= e(t('common.edit')) ?></a>
        <form method="post" action="/leads/<?= e($lead['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button"><?= e(t('common.delete')) ?></button>
        </form>
    </p>
</section>

<?php
$type = 'lead';
$id = (int) $lead['id'];
include $theme->templatesPath() . '/crm/_record_support.php';
?>
