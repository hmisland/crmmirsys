<?php
/**
 * Organization settings → Modules → Modules & Features: the registered
 * modules grouped by purpose, with the organization's real activation
 * status (entitlement) and the latest activation request. No prices, no
 * payment, no technical credentials. Requesting never activates anything.
 *
 * @var array<string, list<array>> $categories ModuleActivationRequestService::catalog()
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;

// A module's catalog name / description when translated, else the registry's own name.
$label = function (string $key, string $fallback): string {
    $translated = t($key);

    return $translated !== $key ? $translated : $fallback;
};
$stateTone = ['active' => 'active', 'pending' => 'pending', 'rejected' => 'rejected', 'available' => 'neutral'];
?>
<div class="page-head">
    <div>
        <h1><?= e(t('catalog.title')) ?><?= help_link('modules-catalog') ?></h1>
        <p><?= e(t('catalog.subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/org/modules" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('catalog.back')) ?></a>
    </div>
</div>

<?php foreach ($categories as $category => $modules): ?>
<section class="card" id="category-<?= e($category) ?>">
    <h2><?= icon('layers') ?><?= e(t('catalog.category.' . $category)) ?></h2>
    <p class="field-hint"><?= e(t('catalog.category.' . $category . '_hint')) ?></p>
    <div class="provider-grid">
    <?php foreach ($modules as $m): ?>
        <?php $state = (string) $m['state']; $request = $m['request']; ?>
        <article class="card provider-card<?= $m['child'] ? ' provider-card--addon' : '' ?>" id="module-<?= e($m['key']) ?>">
            <div class="provider-card__head">
                <h3><?= e($label('catalog.module.' . $m['key'] . '.name', (string) $m['name'])) ?></h3>
                <?= badge(t('catalog.state.' . $state), $stateTone[$state] ?? null) ?>
            </div>
            <p><?= e($label('catalog.module.' . $m['key'] . '.desc', '')) ?></p>

            <?php if ($m['highlights'] !== []): ?>
                <ul class="capability-list">
                    <?php foreach ($m['highlights'] as $point): ?>
                        <li><?= icon('check-circle') ?><?= e(t($point)) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php foreach ($m['dependencies'] as $dep): ?>
                <p class="field-hint"><?= icon('link') ?> <?= e(t('catalog.requires', ['module' => $label('catalog.module.' . $dep['key'] . '.name', $dep['name'])])) ?>
                    <?= $dep['active'] ? badge(t('catalog.state.active'), 'active') : badge(t('catalog.dependency_inactive'), 'pending') ?></p>
            <?php endforeach; ?>
            <?php if ($m['dependents'] !== []): ?>
                <p class="field-hint"><?= e(t('catalog.extended_by', ['modules' => implode(', ', array_map(fn ($k) => $label('catalog.module.' . $k . '.name', $k), $m['dependents']))])) ?></p>
            <?php endif; ?>

            <?php if ($state === 'pending'): ?>
                <p class="muted"><?= icon('clock') ?> <?= e(t('catalog.pending_since')) ?> <span class="ltr"><?= e(local_time($request['created_at']) ?? '') ?></span></p>
            <?php elseif ($state === 'rejected'): ?>
                <div class="alert alert--warning"><?= e(t('catalog.rejected_note')) ?><?= !empty($request['rejection_reason']) ? ' ' . e(t('catalog.notice.reason', ['reason' => (string) $request['rejection_reason']])) : '' ?></div>
            <?php endif; ?>

            <div class="actions">
                <?php if ($state === 'active'): ?>
                    <?php if ($m['open_url'] !== null): ?>
                        <a href="<?= e($m['open_url']) ?>" class="btn"><?= icon('arrow-right') ?><?= e(t('catalog.open')) ?></a>
                    <?php endif; ?>
                <?php elseif ($state === 'available' || $state === 'rejected'): ?>
                    <form method="post" action="/org/modules/catalog/<?= e($m['key']) ?>/request">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--primary" data-confirm="<?= e(t('catalog.request_confirm')) ?>"><?= icon('send') ?><?= e(t($state === 'rejected' ? 'catalog.request_again' : 'catalog.request')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<?php if ($categories === []): ?>
    <section class="card"><p class="muted"><?= e(t('catalog.none')) ?></p></section>
<?php endif; ?>
