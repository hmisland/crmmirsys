<?php
/** @var int $contactsCount */
/** @var int $companiesCount */
/** @var array|null $wallet */
/** @var array|null $tasks */
/** @var int|null $unreadMailCount */
/** @var int|null $openTicketsCount */
/** @var int|null $productsCount */
/** @var int|null $proformasCount */
/** @var int|null $invoicesCount */
/** @var array|null $topFollowUps */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\CurrentUser;

$me = CurrentUser::user();
$greetingName = trim((string) ($me['first_name'] ?? ''));

// Every tile maps 1:1 to a value the controller already provides; a
// tile whose module isn't available (value === null) is simply omitted.
$kpis = [
    ['label' => t('dashboard.contacts_count'), 'value' => $contactsCount, 'href' => '/contacts', 'link' => t('nav.contacts'), 'icon' => 'users', 'tone' => ''],
    ['label' => t('dashboard.companies_count'), 'value' => $companiesCount, 'href' => '/companies', 'link' => t('nav.companies'), 'icon' => 'building', 'tone' => 'info'],
];
if ($wallet !== null) {
    $kpis[] = ['label' => t('dashboard.wallet_balance'), 'value' => (float) $wallet['balance'], 'unit' => $wallet['currency_code'], 'href' => '/wallet', 'link' => t('wallet.my_wallet'), 'icon' => 'wallet', 'tone' => 'success'];
}
if ($unreadMailCount !== null) {
    $kpis[] = ['label' => t('dashboard.unread_mail'), 'value' => $unreadMailCount, 'href' => '/mail', 'link' => t('mail.inbox'), 'icon' => 'mail', 'tone' => ''];
}
if ($openTicketsCount !== null) {
    $kpis[] = ['label' => t('dashboard.open_tickets'), 'value' => $openTicketsCount, 'href' => '/support/tickets/board', 'link' => t('tickets.title'), 'icon' => 'life-buoy', 'tone' => $openTicketsCount > 0 ? 'warning' : ''];
}
if ($invoicesCount !== null) {
    $kpis[] = ['label' => t('dashboard.invoices_count'), 'value' => $invoicesCount, 'href' => '/finance/invoices', 'link' => t('invoices.title'), 'icon' => 'receipt', 'tone' => 'info'];
}
if ($proformasCount !== null) {
    $kpis[] = ['label' => t('dashboard.proformas_count'), 'value' => $proformasCount, 'href' => '/finance/proformas', 'link' => t('proformas.title'), 'icon' => 'file-text', 'tone' => ''];
}
if ($productsCount !== null) {
    $kpis[] = ['label' => t('dashboard.products_count'), 'value' => $productsCount, 'href' => '/finance/products', 'link' => t('products.title'), 'icon' => 'package', 'tone' => ''];
}
?>
<div class="page-head">
    <div>
        <h1><?= $greetingName !== '' ? e(t('dashboard.greeting', ['name' => $greetingName])) : e(t('dashboard.title')) ?></h1>
        <p><?= e(t('dashboard.subtitle')) ?></p>
    </div>
    <div class="quick-actions" aria-label="<?= e(t('dashboard.quick_actions')) ?>">
        <a href="/contacts/create" class="btn btn--primary"><?= icon('user-plus') ?><?= e(t('contacts.new')) ?></a>
        <a href="/companies/create" class="btn"><?= icon('building') ?><?= e(t('companies.new')) ?></a>
        <a href="/leads/create" class="btn"><?= icon('target') ?><?= e(t('leads.new')) ?></a>
    </div>
</div>

<section class="card">
    <form method="get" action="/search" class="search-hero" role="search">
        <div class="search-hero__field">
            <?= icon('search') ?>
            <label for="dashboard-q" class="visually-hidden"><?= e(t('common.search')) ?></label>
            <input type="search" id="dashboard-q" name="q" placeholder="<?= e(t('dashboard.search_placeholder')) ?>">
        </div>
        <button type="submit" class="btn btn--primary"><?= icon('search') ?><?= e(t('common.search')) ?></button>
    </form>
</section>

<section aria-labelledby="dashboard-overview">
    <h2 id="dashboard-overview" class="visually-hidden"><?= e(t('dashboard.overview')) ?></h2>
    <div class="kpi-grid">
        <?php foreach ($kpis as $kpi): ?>
            <a href="<?= e($kpi['href']) ?>" class="card kpi">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e($kpi['label']) ?></span>
                    <span class="kpi__icon<?= $kpi['tone'] !== '' ? ' kpi__icon--' . e($kpi['tone']) : '' ?>"><?= icon($kpi['icon']) ?></span>
                </span>
                <span class="kpi__value">
                    <?= e(number_format($kpi['value'], 0)) ?><?php if (!empty($kpi['unit'])): ?> <span class="kpi__unit"><?= e($kpi['unit']) ?></span><?php endif; ?>
                </span>
                <span class="kpi__link"><?= e($kpi['link']) ?><?= icon('arrow-right', 'icon--sm') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($tasks !== null || $topFollowUps !== null): ?>
<div class="dashboard-columns">

    <?php if ($tasks !== null): ?>
    <section class="card">
        <div class="card__header">
            <h2><?= icon('check-square') ?><?= e(t('dashboard.my_tasks')) ?></h2>
            <a href="/tasks" class="btn btn--ghost btn--sm"><?= e(t('common.view_all')) ?><?= icon('arrow-right', 'icon--sm') ?></a>
        </div>

        <?php
        $groups = [
            'overdue' => [t('dashboard.tasks_overdue'), 'count--danger'],
            'due_today' => [t('dashboard.tasks_due_today'), 'count--warning'],
            'upcoming' => [t('dashboard.tasks_upcoming'), ''],
        ];
        ?>
        <?php foreach ($groups as $key => [$label, $countClass]): ?>
            <?php $rows = $tasks[$key]; ?>
            <div class="task-group">
                <h3 class="task-group__title">
                    <?= e($label) ?>
                    <span class="count <?= $rows !== [] ? e($countClass) : '' ?>"><?= count($rows) ?></span>
                </h3>
                <?php if ($rows === []): ?>
                    <p class="item-list--empty"><?= e(t('dashboard.tasks_none_in_group')) ?></p>
                <?php else: ?>
                    <ul class="item-list">
                        <?php foreach ($rows as $task): ?>
                            <li>
                                <span class="dot dot--<?= e($task['priority']) ?>" title="<?= e(t('tasks.priority.' . $task['priority'])) ?>"></span>
                                <div class="item-list__main">
                                    <a href="/tasks/<?= e($task['id']) ?>"><?= e($task['title']) ?></a>
                                    <span class="item-list__meta">
                                        <?= $task['due_at'] ? e(t('tasks.due')) . ' ' . e(substr((string) $task['due_at'], 0, 10)) : e(t('tasks.no_due_date')) ?>
                                        · <?= e(t('tasks.priority.' . $task['priority'])) ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($topFollowUps !== null): ?>
    <section class="card">
        <div class="card__header">
            <h2><?= icon('calendar-check') ?><?= e(t('dashboard.my_followups')) ?></h2>
            <a href="/followups" class="btn btn--ghost btn--sm"><?= e(t('common.view_all')) ?><?= icon('arrow-right', 'icon--sm') ?></a>
        </div>

        <?php if ($topFollowUps === []): ?>
            <div class="empty-state">
                <span class="empty-state__icon"><?= icon('calendar-check') ?></span>
                <p><?= e(t('followups.empty')) ?></p>
            </div>
        <?php else: ?>
            <ul class="item-list">
                <?php foreach ($topFollowUps as $f): ?>
                    <li>
                        <span class="dot dot--<?= e($f['priority']) ?>" title="<?= e(t('followups.priority.' . $f['priority'])) ?>"></span>
                        <div class="item-list__main">
                            <a href="/followups/<?= e($f['id']) ?>"><?= e($f['title']) ?></a>
                            <span class="item-list__meta">
                                <?= $f['due_at'] ? e(t('followups.due_at')) . ' ' . e(substr((string) $f['due_at'], 0, 16)) : e(t('followups.no_due_date')) ?>
                                · <?= e(t('followups.priority.' . $f['priority'])) ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>
<?php endif; ?>
