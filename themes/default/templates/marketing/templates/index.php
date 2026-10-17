<?php
/** @var array $templates */
/** @var bool $canManage */
/** @var bool $canCreateCampaign */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$active = 'templates';
?>
<div class="page-head">
    <div><h1><?= e(t('marketing.nav.templates')) ?></h1></div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<section class="card">
    <?php if ($canManage): ?>
    <div class="actions">
        <a href="/marketing/templates/create?channel=sms" class="btn btn--primary"><?= icon('plus') ?><?= e(t('marketing.new_sms_template')) ?></a>
        <a href="/marketing/templates/create?channel=email" class="btn"><?= icon('plus') ?><?= e(t('marketing.new_email_template')) ?></a>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('marketing.channel')) ?></th>
            <th><?= e(t('marketing.content')) ?></th>
            <th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($templates as $tpl): ?>
            <tr>
                <td><?= e($tpl['name']) ?></td>
                <td><?= e(t('marketing.channel.' . $tpl['channel'])) ?></td>
                <td class="muted"><span class="cell-clip"><?= e($tpl['channel'] === 'email' ? (string) $tpl['subject'] : (string) $tpl['body_text']) ?></span></td>
                <td class="actions-cell">
                    <div class="actions">
                        <?php if ($canCreateCampaign): ?>
                            <a href="/marketing/campaigns/create?channel=<?= e($tpl['channel']) ?>&amp;template=<?= e($tpl['id']) ?>" class="btn btn--sm"><?= icon('megaphone') ?><?= e(t('marketing.use_template')) ?></a>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <a href="/marketing/templates/<?= e($tpl['id']) ?>/edit" class="btn btn--sm"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                            <form method="post" action="/marketing/templates/<?= e($tpl['id']) ?>/delete">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($templates === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('marketing.no_templates')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
