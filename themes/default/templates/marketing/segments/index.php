<?php
/** @var array $segments */
/** @var array<int, int> $memberCounts */
/** @var array{q: string, type: string} $filters */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var bool $canManage */
/** @var bool $canSeeContacts */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$active = 'segments';
?>
<div class="page-head">
    <div>
        <h1><?= e(t('marketing.nav.segments')) ?><?= help_link('segments-introduction') ?></h1>
        <p><?= e(t('marketing.segment.subtitle')) ?></p>
    </div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<section class="card">
    <?php if ($canManage): ?>
    <div class="actions">
        <a href="/marketing/segments/create?type=dynamic" class="btn btn--primary"><?= icon('plus') ?><?= e(t('marketing.segment.new_dynamic')) ?></a>
        <?php if ($canSeeContacts): ?>
            <a href="/marketing/segments/create?type=static" class="btn"><?= icon('plus') ?><?= e(t('marketing.segment.new_static')) ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="get" action="/marketing/segments" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.search')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="type" aria-label="<?= e(t('marketing.segment.type_label')) ?>">
            <option value=""><?= e(t('marketing.segment.all_types')) ?></option>
            <?php foreach (['dynamic', 'static'] as $type): ?>
                <option value="<?= e($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= e(t('marketing.segment.type.' . $type)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/marketing/segments" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('marketing.segment.type_label')) ?></th>
            <th class="num"><?= e(t('marketing.segment.members')) ?></th>
            <th><?= e(t('marketing.segment.updated_at')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($segments as $s): ?>
            <tr>
                <td>
                    <a href="/marketing/segments/<?= e($s['id']) ?>"><?= e($s['name']) ?></a>
                    <?php if (!empty($s['description'])): ?><div class="muted text-sm"><?= e($s['description']) ?></div><?php endif; ?>
                </td>
                <td><?= badge(t('marketing.segment.type.' . $s['type']), $s['type'] === 'dynamic' ? 'new' : 'neutral') ?></td>
                <td class="num"><?= $s['type'] === 'static' ? e(number_format($memberCounts[(int) $s['id']] ?? 0)) : '<span class="muted">' . e(t('marketing.segment.computed_live')) . '</span>' ?></td>
                <td class="muted"><span class="ltr"><?= e(local_time($s['updated_at'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($segments === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('marketing.segment.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
