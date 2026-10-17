<?php
/** @var array $segment */
/** @var array $rulesSummary match, groups (dynamic) */
/** @var array{total: int, rows: array, rows_visible: bool} $preview */
/** @var int $previewPage */
/** @var int $previewPages */
/** @var bool $canManage */
/** @var bool $canEdit manage + not locked by personal-field rules */
/** @var bool $canSeeContacts */
/** @var array{rows: array, total: int}|null $search */
/** @var string $q */
/** @var int $findPage */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$id = (int) $segment['id'];
$isStatic = $segment['type'] === 'static';
$active = 'segments';
?>
<div class="page-head">
    <div>
        <h1><?= e($segment['name']) ?><?= help_link($isStatic ? 'segments-static' : 'segments-in-campaigns') ?></h1>
        <p><?= badge(t('marketing.segment.type.' . $segment['type']), $isStatic ? 'neutral' : 'new') ?> <?= e((string) ($segment['description'] ?? '')) ?></p>
    </div>
    <div class="actions">
        <a href="/marketing/campaigns/create?channel=sms&amp;segment=<?= $id ?>" class="btn btn--primary"><?= icon('megaphone') ?><?= e(t('marketing.segment.use_in_campaign')) ?></a>
        <?php if ($canEdit): ?>
            <a href="/marketing/segments/<?= $id ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <form method="post" action="/marketing/segments/<?= $id ?>/delete">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('marketing.segment.delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<section class="card">
    <dl class="fact-row">
        <div class="fact"><dt><?= icon('clock') ?><?= e(t('marketing.segment.created_at')) ?></dt><dd><span class="ltr"><?= e(local_time($segment['created_at'])) ?></span></dd></div>
        <div class="fact"><dt><?= icon('history') ?><?= e(t('marketing.segment.updated_at')) ?></dt><dd><span class="ltr"><?= e(local_time($segment['updated_at'])) ?></span></dd></div>
        <div class="fact"><dt><?= icon('users') ?><?= e(t('marketing.segment.members')) ?></dt><dd><?= e(number_format($preview['total'])) ?></dd></div>
    </dl>

    <?php if (!$isStatic): ?>
        <h2><?= e(t('marketing.segment.rules')) ?></h2>
        <p class="field-hint"><?= e(t($rulesSummary['match'] === 'any' ? 'marketing.segment.builder.groups_any' : 'marketing.segment.builder.groups_all')) ?></p>
        <ol class="segment-rules">
            <?php foreach ($rulesSummary['groups'] as $group): ?>
                <li>
                    <span class="muted text-sm"><?= e(t($group['match'] === 'any' ? 'marketing.segment.builder.match_any' : 'marketing.segment.builder.match_all')) ?></span>
                    <ul>
                        <?php foreach ($group['conditions'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(t($isStatic ? 'marketing.segment.members' : 'marketing.segment.preview')) ?></h2>
    <?php if ($isStatic && $canManage && $canSeeContacts && $preview['rows'] !== []): ?>
        <form method="post" action="/marketing/segments/<?= $id ?>/members/remove" id="segment-remove-form">
            <?= Csrf::field() ?>
            <div class="table-responsive">
            <table class="table table--compact">
                <thead><tr><th class="cell-check"><span class="visually-hidden"><?= e(t('marketing.segment.select')) ?></span></th><th><?= e(t('common.name')) ?></th><th><?= e(t('marketing.segment.field.phone')) ?></th><th><?= e(t('marketing.segment.field.email')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($preview['rows'] as $c): ?>
                    <tr>
                        <td class="cell-check"><input type="checkbox" name="contact_ids[]" value="<?= e($c['id']) ?>" aria-label="<?= e(t('marketing.segment.select')) ?>"></td>
                        <td><a href="/contacts/<?= e($c['id']) ?>"><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?></a></td>
                        <td><span class="ltr"><?= e($c['phone'] ?? '') ?></span></td>
                        <td><span class="ltr"><?= e($c['email'] ?? '') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('marketing.segment.remove_confirm')) ?>"><?= icon('trash') ?><?= e(t('marketing.segment.remove_selected')) ?></button>
        </form>
    <?php else: ?>
        <?php include __DIR__ . '/_preview.php'; ?>
    <?php endif; ?>

    <?php if ($previewPages > 1): ?>
        <nav class="pagination" aria-label="<?= e(t('marketing.segment.preview')) ?>">
            <?php if ($previewPage > 1): ?><a class="btn btn--sm" href="?page=<?= $previewPage - 1 ?>"><?= icon('chevron-left') ?><?= e(t('marketing.segment.prev_page')) ?></a><?php endif; ?>
            <span class="muted"><?= e(t('marketing.segment.page_of', ['page' => (string) $previewPage, 'pages' => (string) $previewPages])) ?></span>
            <?php if ($previewPage < $previewPages): ?><a class="btn btn--sm" href="?page=<?= $previewPage + 1 ?>"><?= e(t('marketing.segment.next_page')) ?><?= icon('chevron-right') ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if ($isStatic && $canManage && $canSeeContacts): ?>
<section class="card card--form">
    <h2><?= e(t('marketing.segment.add_members')) ?></h2>
    <form method="get" action="/marketing/segments/<?= $id ?>" class="inline-form-row">
        <input type="hidden" name="find" value="1">
        <label for="segment-q" class="visually-hidden"><?= e(t('common.search')) ?></label>
        <input type="text" id="segment-q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('marketing.segment.search_contacts')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
    </form>

    <?php if ($search !== null): ?>
        <form method="post" action="/marketing/segments/<?= $id ?>/members">
            <?= Csrf::field() ?>
            <p class="field-hint"><?= e(t('marketing.segment.search_results', ['count' => number_format($search['total'])])) ?></p>
            <div class="table-responsive">
            <table class="table table--compact">
                <thead><tr><th class="cell-check"><span class="visually-hidden"><?= e(t('marketing.segment.select')) ?></span></th><th><?= e(t('common.name')) ?></th><th><?= e(t('marketing.segment.field.phone')) ?></th><th><?= e(t('marketing.segment.field.email')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($search['rows'] as $c): ?>
                    <tr>
                        <td class="cell-check"><input type="checkbox" name="contact_ids[]" value="<?= e($c['id']) ?>" <?= !empty($c['in_segment']) ? 'checked disabled' : '' ?> aria-label="<?= e(t('marketing.segment.select')) ?>"></td>
                        <td><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?><?php if (!empty($c['in_segment'])): ?> <span class="muted text-sm">(<?= e(t('marketing.segment.already_member')) ?>)</span><?php endif; ?></td>
                        <td><span class="ltr"><?= e($c['phone'] ?? '') ?></span></td>
                        <td><span class="ltr"><?= e($c['email'] ?? '') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($search['rows'] === []): ?>
                    <tr><td colspan="4" class="muted"><?= e(t('marketing.segment.no_matches')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('marketing.segment.add_selected')) ?></button>
                <?php if ($search['total'] > $findPage * \Modules\Marketing\Services\SegmentService::PREVIEW_PAGE): ?>
                    <a class="btn btn--ghost" href="?find=1&amp;q=<?= e(rawurlencode($q)) ?>&amp;find_page=<?= $findPage + 1 ?>"><?= e(t('marketing.segment.next_page')) ?><?= icon('chevron-right') ?></a>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
