<?php
/** @var array $contract decorated: + effective_status */
/** @var array<string, bool> $actions ContractService::allowedActions() */
/** @var string|null $category */
/** @var array|null $contact */
/** @var array|null $company */
/** @var string|null $currencyCode */
/** @var array|null $parent */
/** @var array $renewals */
/** @var array $links ContractService::links() — url null when the other module/record isn't viewable */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var array $attachments */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

include __DIR__ . '/_helpers.php';

$id = (int) $contract['id'];
$base = '/contracts/' . $id;
$status = $contract['effective_status'];
$facts = array_filter([
    ['tag', t('contracts.category'), $category, false],
    ['calendar', t('contracts.start_date'), $contract['start_date'] ? $fmt->date($contract['start_date']) : null, false],
    ['calendar', t('contracts.end_date'), $contract['end_date'] ? $fmt->date($contract['end_date']) : null, false],
    ['check-circle', t('contracts.signed_at'), $contract['signed_at'] ? $fmt->date($contract['signed_at']) : null, false],
    ['ban', t('contracts.terminated_at'), $contract['terminated_at'] ? $fmt->date($contract['terminated_at']) : null, false],
    ['coins', t('contracts.value'), $contract['value'] !== null ? $contractValue($fmt, $contract['value'], $currencyCode) : null, false],
    ['bell', t('contracts.renewal_notice_days'), $contract['renewal_notice_days'] !== null ? $fmt->number((string) $contract['renewal_notice_days']) : null, false],
    ['refresh', t('contracts.auto_renew'), !empty($contract['auto_renew']) ? t('common.yes') : null, false],
], fn ($f) => $f[2] !== null && $f[2] !== '');
$hasLifecycle = $actions['submit'] || $actions['return_to_draft'] || $actions['approve'] || $actions['activate']
    || $actions['cancel'] || $actions['terminate'] || $actions['renew'];
?>
<section class="card">
    <h1><?= e($contract['title']) ?></h1>
    <p class="muted">
        <?php if (!empty($contract['contract_number'])): ?><span class="ltr"><?= e($contract['contract_number']) ?></span> · <?php endif; ?>
        <?= badge(t('contracts.status.' . $status), $contractTone($status)) ?>
        <?php if ($contract['relation'] !== 'original'): ?> · <?= e(t('contracts.relation.' . $contract['relation'])) ?><?php endif; ?>
    </p>

    <?php if ($contact !== null || $company !== null): ?>
        <p>
            <?= e(t('contracts.party')) ?>:
            <?php if ($contact !== null): ?><a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a><?php endif; ?>
            <?php if ($contact !== null && $company !== null): ?> · <?php endif; ?>
            <?php if ($company !== null): ?><a href="/companies/<?= e($company['id']) ?>"><?= e($company['name']) ?></a><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($facts !== []): ?>
        <dl class="fact-row">
            <?php foreach ($facts as [$factIcon, $factLabel, $factValue, $factLtr]): ?>
                <div class="fact">
                    <dt><?= icon($factIcon) ?><?= e($factLabel) ?></dt>
                    <dd<?= $factLtr ? ' class="ltr"' : '' ?>><?= e((string) $factValue) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php if (!empty($contract['description'])): ?>
        <p class="prose-text"><?= nl2br(e($contract['description'])) ?></p>
    <?php endif; ?>

    <div class="actions">
        <?php if ($actions['edit']): ?>
            <a href="<?= e($base) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
        <a href="/contracts" class="btn btn--ghost"><?= icon('list') ?><?= e(t('contracts.back_to_list')) ?></a>
        <?php if ($actions['delete']): ?>
            <form method="post" action="<?= e($base) ?>/delete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($hasLifecycle): ?>
<section class="card">
    <h2><?= e(t('contracts.lifecycle')) ?></h2>
    <div class="actions">
        <?php if ($actions['submit']): ?>
            <form method="post" action="<?= e($base) ?>/submit" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn"><?= icon('send') ?><?= e(t('contracts.action.submit')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($actions['return_to_draft']): ?>
            <form method="post" action="<?= e($base) ?>/return" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn"><?= icon('undo') ?><?= e(t('contracts.action.return_to_draft')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($actions['cancel']): ?>
            <form method="post" action="<?= e($base) ?>/cancel" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger" data-confirm><?= icon('x-circle') ?><?= e(t('contracts.action.cancel')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($actions['renew']): ?>
            <form method="post" action="<?= e($base) ?>/renew" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn"><?= icon('refresh') ?><?= e(t('contracts.action.renew')) ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php foreach (['approve' => 'check-circle', 'activate' => 'check'] as $activation => $activationIcon): ?>
        <?php if ($actions[$activation]): ?>
            <form method="post" action="<?= e($base) ?>/<?= e($activation) ?>" class="inline-form-row">
                <?= Csrf::field() ?>
                <label for="signed_on_<?= e($activation) ?>"><?= e(t('contracts.signed_on')) ?></label>
                <input type="text" class="crm-datepicker" id="signed_on_<?= e($activation) ?>" name="signed_on" value="<?= e(date('Y-m-d')) ?>">
                <button type="submit" class="btn btn--primary"><?= icon($activationIcon) ?><?= e(t('contracts.action.' . $activation)) ?></button>
            </form>
            <?php break; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($actions['terminate']): ?>
        <form method="post" action="<?= e($base) ?>/terminate" class="inline-form-row">
            <?= Csrf::field() ?>
            <input type="text" name="reason" maxlength="500" placeholder="<?= e(t('contracts.terminate_reason')) ?>" aria-label="<?= e(t('contracts.terminate_reason')) ?>">
            <button type="submit" class="btn btn--danger" data-confirm><?= icon('ban') ?><?= e(t('contracts.action.terminate')) ?></button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($contract['terms'])): ?>
<section class="card">
    <h2><?= e(t('contracts.terms')) ?></h2>
    <p class="prose-text"><?= nl2br(e($contract['terms'])) ?></p>
</section>
<?php endif; ?>

<?php if (($links ?? []) !== []): ?>
<section class="card">
    <h2><?= e(t('contracts.linked_records')) ?></h2>
    <ul class="item-list">
        <?php foreach ($links as $link): ?>
            <li>
                <span class="muted"><?= e($link['label_key'] !== null ? t($link['label_key']) : $link['type']) ?>:</span>
                <?php if ($link['url'] !== null): ?>
                    <a href="<?= e($link['url']) ?>"><?= e($link['label']) ?></a>
                <?php else: ?>
                    <span><?= e($link['label']) ?></span>
                <?php endif; ?>
                <?php if ($actions['link']): ?>
                    <form method="post" action="<?= e($base) ?>/links/<?= e($link['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('x') ?><?= e(t('contracts.unlink')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($parent !== null || $renewals !== []): ?>
<section class="card">
    <h2><?= e(t('contracts.history')) ?></h2>
    <ul class="item-list">
        <?php if ($parent !== null): ?>
            <li>
                <?= e(t('contracts.previous_contract')) ?>:
                <a href="/contracts/<?= e($parent['id']) ?>"><?= e($parent['contract_number'] ?? $parent['title']) ?></a>
                <?= badge(t('contracts.status.' . $parent['effective_status']), $contractTone($parent['effective_status'])) ?>
            </li>
        <?php endif; ?>
        <?php foreach ($renewals as $r): ?>
            <li>
                <?= e(t('contracts.relation.' . $r['relation'])) ?>:
                <a href="/contracts/<?= e($r['id']) ?>"><?= e($r['contract_number'] ?? $r['title']) ?></a>
                <?= badge(t('contracts.status.' . $r['effective_status']), $contractTone($r['effective_status'])) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php
$panelSubjectType = 'contract';
$panelSubject = $contract;
include $theme->templatesPath() . '/partials/related-panels.php';

$type = 'contract';
include $theme->templatesPath() . '/crm/_record_support.php';
?>
