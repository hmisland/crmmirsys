<?php
/**
 * Projects dashboard (Phase 3): figures and deadline / delay monitoring over
 * the projects this user may see (ProjectDashboardService). Task figures are
 * contributed by the Task Manager through the Core panel slots
 * (project_dashboard_kpi, project_dashboard, project_list_row) — Projects
 * names only its own slots and works without them.
 *
 * @var string $today
 * @var string $horizon
 * @var int $horizonDays
 * @var array{total: int, active: int, overdue: int, ending_soon: int} $counts
 * @var array{overdue: int, upcoming: int} $milestones
 * @var array $upcoming next planned milestones
 * @var array{rows: array, total: int} $attention
 * @var array<int, string> $open_projects open visible projects (id => label), for the slots
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use App\Infrastructure\Security\CurrentUser;
use Modules\Projects\Services\ProjectDashboardService;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;

include __DIR__ . '/_helpers.php';
$user = CurrentUser::user();
$num = fn (int $n): string => $fmt->number((string) $n);
$rowPanels = ($relatedPanels ?? null)?->panelsFor('project_list_row', $user) ?? [];
$listIds = array_map(fn (array $r) => (int) $r['id'], $attention['rows']);
$kpis = [
    ['href' => '/projects?status=active', 'icon' => 'activity', 'tone' => 'info', 'label' => t('projects.dashboard.active'), 'value' => $counts['active'], 'link' => t('projects.dashboard.view_list')],
    ['href' => '#project-attention', 'icon' => 'alert-triangle', 'tone' => $counts['overdue'] > 0 ? 'danger' : 'success', 'label' => t('projects.dashboard.overdue_projects'), 'value' => $counts['overdue'], 'link' => t('projects.dashboard.view_attention')],
    ['href' => '#project-milestones', 'icon' => 'flag', 'tone' => 'warning', 'label' => t('projects.dashboard.upcoming_milestones'), 'value' => $milestones['upcoming'], 'link' => t('projects.dashboard.view_milestones')],
    ['href' => '#project-attention', 'icon' => 'clock', 'tone' => $milestones['overdue'] > 0 ? 'danger' : 'success', 'label' => t('projects.dashboard.overdue_milestones'), 'value' => $milestones['overdue'], 'link' => t('projects.dashboard.view_attention')],
];
?>
<div class="page-head">
    <div>
        <h1><?= e(t('projects.dashboard.title')) ?><?= help_link('projects-dashboard-delays') ?></h1>
        <p><?= e(t('projects.dashboard.subtitle', ['days' => $num($horizonDays)])) ?></p>
    </div>
    <div class="quick-actions">
        <a href="/projects" class="btn btn--ghost"><?= icon('list') ?><?= e(t('projects.back_to_list')) ?></a>
    </div>
</div>

<?php if ($counts['total'] === 0): ?>
<section class="card">
    <div class="empty-state">
        <span class="empty-state__icon"><?= icon('kanban') ?></span>
        <p><?= e(t('projects.dashboard.no_projects')) ?></p>
    </div>
</section>
<?php else: ?>

<section aria-label="<?= e(t('projects.dashboard.title')) ?>">
    <div class="kpi-grid kpi-grid--compact">
        <?php foreach ($kpis as $kpi): ?>
            <a href="<?= e($kpi['href']) ?>" class="card kpi">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e($kpi['label']) ?></span>
                    <span class="kpi__icon kpi__icon--<?= e($kpi['tone']) ?>"><?= icon($kpi['icon']) ?></span>
                </span>
                <span class="kpi__value"><?= e($num($kpi['value'])) ?></span>
                <span class="kpi__link"><?= e($kpi['link']) ?><?= icon('arrow-right', 'icon--sm') ?></span>
            </a>
        <?php endforeach; ?>
        <?php foreach (($relatedPanels ?? null)?->render('project_dashboard_kpi', ['open_projects' => $open_projects], $user) ?? [] as $rendered): ?>
            <?= $rendered['html'] ?>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" id="project-attention">
    <h2><?= icon('alert-triangle') ?><?= e(t('projects.dashboard.attention')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('projects.name')) ?></th>
            <th><?= e(t('projects.status')) ?></th>
            <?php foreach ($rowPanels as $rowPanel): ?><th><?= e(t($rowPanel->titleKey)) ?></th><?php endforeach; ?>
            <th><?= e(t('projects.expected_end_date')) ?></th>
            <th><?= e(t('projects.dashboard.reason')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($attention['rows'] as $p): ?>
            <tr>
                <td>
                    <a href="/projects/<?= e($p['id']) ?>"><?= e($p['name']) ?></a>
                    <div class="muted text-sm"><span class="ltr"><?= e($p['code']) ?></span></div>
                </td>
                <td><?= badge(t('projects.status.' . $p['status']), $projectTone($p['status'])) ?></td>
                <?php if ($rowPanels !== []): ?>
                    <?php $rowCells = []; foreach ($relatedPanels->render('project_list_row', $p + ['list_ids' => $listIds], $user) as $rendered) { $rowCells[$rendered['panel']->id] = $rendered['html']; } ?>
                    <?php foreach ($rowPanels as $rowPanel): ?><td class="project-list__extra"><?= $rowCells[$rowPanel->id] ?? '—' ?></td><?php endforeach; ?>
                <?php endif; ?>
                <td class="nowrap"><?= $p['expected_end_date'] ? '<span class="ltr">' . e($fmt->date($p['expected_end_date'])) . '</span>' : '—' ?></td>
                <td>
                    <?php if ((int) $p['is_overdue'] === 1): ?>
                        <span class="badge badge--danger"><?= e(t('projects.dashboard.reason_overdue', ['days' => $num(ProjectDashboardService::daysBetween((string) $p['expected_end_date'], $today))])) ?></span>
                    <?php elseif ((int) $p['ends_soon'] === 1): ?>
                        <?php $left = ProjectDashboardService::daysBetween($today, (string) $p['expected_end_date']); ?>
                        <span class="badge badge--warning"><?= e($left === 0 ? t('projects.dashboard.reason_ends_today') : t('projects.dashboard.reason_ends_soon', ['days' => $num($left)])) ?></span>
                    <?php endif; ?>
                    <?php if ((int) $p['overdue_milestones'] > 0): ?>
                        <span class="badge badge--danger"><?= e(t('projects.dashboard.reason_milestones', ['count' => $num((int) $p['overdue_milestones'])])) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($attention['rows'] === []): ?>
            <tr><td colspan="<?= 4 + count($rowPanels) ?>" class="muted"><?= e(t('projects.dashboard.attention_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($attention['total'] > count($attention['rows'])): ?>
        <p class="muted"><?= e(t('projects.dashboard.showing', ['shown' => $num(count($attention['rows'])), 'total' => $num($attention['total'])])) ?></p>
    <?php endif; ?>
</section>

<section class="card" id="project-milestones">
    <h2><?= icon('flag') ?><?= e(t('projects.dashboard.upcoming_milestones')) ?></h2>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('projects.milestones.name')) ?></th>
            <th><?= e(t('projects.record')) ?></th>
            <th><?= e(t('projects.milestones.date')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($upcoming as $m): ?>
            <?php $left = ProjectDashboardService::daysBetween($today, (string) $m['date']); ?>
            <tr>
                <td><?= e($m['name']) ?></td>
                <td><a href="/projects/<?= e($m['project_id']) ?>"><?= e($m['project_name']) ?></a> <span class="muted text-sm ltr"><?= e($m['project_code']) ?></span></td>
                <td class="nowrap"><span class="ltr"><?= e($fmt->date($m['date'])) ?></span> <span class="muted text-sm">(<?= e($left === 0 ? t('projects.dashboard.today') : t('projects.dashboard.in_days', ['days' => $num($left)])) ?>)</span></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($upcoming === []): ?>
            <tr><td colspan="3" class="muted"><?= e(t('projects.dashboard.milestones_empty', ['days' => $num($horizonDays)])) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php
$panelSubjectType = 'project_dashboard';
$panelSubject = ['open_projects' => $open_projects];
include $theme->templatesPath() . '/partials/related-panels.php';
?>
<?php endif; ?>
