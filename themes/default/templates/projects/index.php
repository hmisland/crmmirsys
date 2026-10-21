<?php
/** @var array $projects */
/** @var array<int, array{edit: bool, delete: bool, reason: string}> $rowActions */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var string $q */
/** @var string $status */
/** @var bool $mine */
/** @var array $partyFilter */
/** @var bool $canCreate */
/** @var bool $canManageSettings */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

include __DIR__ . '/_helpers.php';
?>
<section class="card">
    <h1><?= e(t('projects.title')) ?><?= help_link('projects-getting-started') ?></h1>

    <div class="actions">
        <?php if ($canCreate): ?>
            <a href="/projects/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('projects.new')) ?></a>
        <?php endif; ?>
        <a href="/projects/dashboard" class="btn"><?= icon('bar-chart') ?><?= e(t('projects.dashboard.title')) ?></a>
        <?php if ($canManageSettings): ?>
            <a href="/projects/settings" class="btn"><?= icon('settings') ?><?= e(t('projects.settings_title')) ?></a>
        <?php endif; ?>
    </div>

    <form method="get" action="/projects" class="inline-form-row filter-bar">
        <?php foreach ($partyFilter as $partyKey => $partyId): ?>
            <input type="hidden" name="<?= e($partyKey) ?>" value="<?= e($partyId) ?>">
        <?php endforeach; ?>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('projects.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="status" aria-label="<?= e(t('projects.status')) ?>">
            <option value=""><?= e(t('projects.all_statuses')) ?></option>
            <?php foreach (['draft', 'planned', 'active', 'on_hold', 'completed', 'cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t('projects.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="check"><input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?>> <?= e(t('projects.only_mine')) ?></label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/projects" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php
    // Extra per-row columns other modules contribute (e.g. task progress) through
    // the Core panel slot — Projects names only its own subject type. Each row
    // carries the page's ids so a contributor can answer the whole page at once.
    $listUser = \App\Infrastructure\Security\CurrentUser::user();
    $rowPanels = ($relatedPanels ?? null)?->panelsFor('project_list_row', $listUser) ?? [];
    $listIds = array_map(fn (array $row) => (int) $row['id'], $projects);
    ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('projects.name')) ?></th>
            <th><?= e(t('projects.party')) ?></th>
            <th><?= e(t('projects.manager')) ?></th>
            <th><?= e(t('projects.status')) ?></th>
            <th><?= e(t('projects.period')) ?></th>
            <?php foreach ($rowPanels as $rowPanel): ?><th><?= e(t($rowPanel->titleKey)) ?></th><?php endforeach; ?>
            <th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($projects as $p): ?>
            <?php $actions = $rowActions[(int) $p['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td>
                    <a href="/projects/<?= e($p['id']) ?>"><?= e($p['name']) ?></a>
                    <div class="muted text-sm"><span class="ltr"><?= e($p['code']) ?></span></div>
                </td>
                <td><?= e($projectParty($p)) ?></td>
                <td><?= e(trim((string) ($p['owner_name'] ?? '')) ?: '—') ?></td>
                <td><?= badge(t('projects.status.' . $p['status']), $projectTone($p['status'])) ?></td>
                <td class="nowrap"><?= e($fmt->date($p['start_date'])) ?> – <?= e($fmt->date($p['expected_end_date'])) ?></td>
                <?php if ($rowPanels !== []): ?>
                    <?php $rowCells = []; foreach ($relatedPanels->render('project_list_row', $p + ['list_ids' => $listIds], $listUser) as $rendered) { $rowCells[$rendered['panel']->id] = $rendered['html']; } ?>
                    <?php foreach ($rowPanels as $rowPanel): ?><td class="project-list__extra"><?= $rowCells[$rowPanel->id] ?? '—' ?></td><?php endforeach; ?>
                <?php endif; ?>
                <td class="actions-cell"><?php $editUrl = '/projects/' . $p['id'] . '/edit'; $deleteUrl = '/projects/' . $p['id'] . '/delete'; include __DIR__ . '/../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($projects === []): ?>
            <tr><td colspan="<?= 6 + count($rowPanels) ?>" class="muted"><?= e(t('projects.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
