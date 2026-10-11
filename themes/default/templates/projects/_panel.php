<?php
/**
 * Body of the "Projects" panel on a contact, company, deal or contract
 * page (Modules\Projects\ProjectPanels). The heading and card come from
 * partials/related-panels.php.
 *
 * @var array $projects up to ProjectPanels::LIMIT, newest first, already in the user's scope
 * @var int $total
 * @var bool $canCreate
 * @var string $createUrl
 * @var string $listUrl '' = no "view all" link
 * @var string $emptyKey translation key for the empty row
 * @var string|null $linkType 'deal' | 'contract' on those pages, else null
 * @var int $linkId the host record's id
 * @var array $linkable projects the user may link to this record
 * @var array<int, int> $unlinks contract page (Stage 3-A): project id => link id the user may remove
 * @var string $returnTo contract page URL to come back to after an unlink
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

include __DIR__ . '/_helpers.php';
$unlinks = $unlinks ?? [];
$returnTo = $returnTo ?? '';
?>
<?php if ($canCreate || ($total > count($projects) && $listUrl !== '')): ?>
<div class="actions">
    <?php if ($canCreate): ?>
        <a href="<?= e($createUrl) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('projects.new')) ?></a>
    <?php endif; ?>
    <?php if ($total > count($projects) && $listUrl !== ''): ?>
        <a href="<?= e($listUrl) ?>" class="btn btn--sm btn--ghost"><?= icon('list') ?><?= e(t('projects.view_all', ['count' => $fmt->number((string) $total)])) ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('projects.name')) ?></th>
        <th><?= e(t('projects.status')) ?></th>
        <th><?= e(t('projects.period')) ?></th>
        <?php if ($unlinks !== []): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($projects as $p): ?>
        <tr>
            <td>
                <a href="/projects/<?= e($p['id']) ?>"><?= e($p['name']) ?></a>
                <div class="muted text-sm"><span class="ltr"><?= e($p['code']) ?></span></div>
            </td>
            <td><?= badge(t('projects.status.' . $p['status']), $projectTone($p['status'])) ?></td>
            <td class="nowrap"><?= e($fmt->date($p['start_date'])) ?> – <?= e($fmt->date($p['expected_end_date'])) ?></td>
            <?php if ($unlinks !== []): ?>
                <td>
                    <?php if (isset($unlinks[(int) $p['id']])): ?>
                        <form method="post" action="/projects/<?= e($p['id']) ?>/links/<?= e($unlinks[(int) $p['id']]) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('x') ?><?= e(t('projects.unlink')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if ($projects === []): ?>
        <tr><td colspan="<?= $unlinks !== [] ? 4 : 3 ?>" class="muted"><?= e(t($emptyKey)) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($linkType !== null && $linkable !== []): ?>
    <form method="post" action="/projects/links" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="hidden" name="linked_type" value="<?= e($linkType) ?>">
        <input type="hidden" name="linked_id" value="<?= e($linkId) ?>">
        <select name="project_id" aria-label="<?= e(t('projects.link_choose')) ?>">
            <?php foreach ($linkable as $candidate): ?>
                <option value="<?= e($candidate['id']) ?>"><?= e($candidate['code'] . ' — ' . $candidate['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--sm"><?= icon('arrow-left-right') ?><?= e(t('projects.link_existing')) ?></button>
    </form>
<?php endif; ?>
