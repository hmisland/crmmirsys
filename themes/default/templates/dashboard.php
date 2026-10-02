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
?>
<section class="card">
    <form method="get" action="/search" class="inline-form-row">
        <input type="text" name="q" placeholder="<?= e(t('dashboard.search_placeholder')) ?>" style="flex:1">
        <button type="submit" class="btn btn--primary"><?= e(t('common.search')) ?></button>
    </form>
</section>

<div class="dashboard-grid">

    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.contacts_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($contactsCount)) ?></p>
        <a href="/contacts"><?= e(t('nav.contacts')) ?> →</a>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.companies_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($companiesCount)) ?></p>
        <a href="/companies"><?= e(t('nav.companies')) ?> →</a>
    </section>

    <?php if ($wallet !== null): ?>
    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.wallet_balance')) ?></h2>
        <p class="dashboard-tile__number">
            <?= e(number_format((float) $wallet['balance'], 0)) ?>
            <span class="dashboard-tile__unit"><?= e($wallet['currency_code']) ?></span>
        </p>
        <a href="/wallet"><?= e(t('wallet.my_wallet')) ?> →</a>
    </section>
    <?php endif; ?>

    <?php if ($unreadMailCount !== null): ?>
    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.unread_mail')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($unreadMailCount)) ?></p>
        <a href="/mail"><?= e(t('mail.inbox')) ?> →</a>
    </section>
    <?php endif; ?>

    <?php if ($openTicketsCount !== null): ?>
    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.open_tickets')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($openTicketsCount)) ?></p>
        <a href="/support/tickets/board"><?= e(t('tickets.title')) ?> →</a>
    </section>
    <?php endif; ?>

    <?php if ($invoicesCount !== null): ?>
    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.invoices_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($invoicesCount)) ?></p>
        <a href="/finance/invoices"><?= e(t('invoices.title')) ?> →</a>
    </section>
    <?php endif; ?>

    <?php if ($proformasCount !== null): ?>
    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.proformas_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($proformasCount)) ?></p>
        <a href="/finance/proformas"><?= e(t('proformas.title')) ?> →</a>
    </section>
    <?php endif; ?>

    <?php if ($productsCount !== null): ?>
    <section class="card dashboard-tile">
        <h2><?= e(t('dashboard.products_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($productsCount)) ?></p>
        <a href="/finance/products"><?= e(t('products.title')) ?> →</a>
    </section>
    <?php endif; ?>

</div>

<?php if ($tasks !== null): ?>
<section class="card">
    <h2><?= e(t('dashboard.my_tasks')) ?></h2>

    <?php
    $groups = [
        'overdue' => t('dashboard.tasks_overdue'),
        'due_today' => t('dashboard.tasks_due_today'),
        'upcoming' => t('dashboard.tasks_upcoming'),
    ];
    ?>
    <?php foreach ($groups as $key => $label): ?>
        <?php $rows = $tasks[$key]; ?>
        <h3 style="margin-top:1rem">
            <?= e($label) ?> <span class="muted">(<?= count($rows) ?>)</span>
        </h3>
        <?php if ($rows === []): ?>
            <p class="muted"><?= e(t('dashboard.tasks_none_in_group')) ?></p>
        <?php else: ?>
            <ul class="note-list">
                <?php foreach ($rows as $task): ?>
                    <li>
                        <a href="/tasks/<?= e($task['id']) ?>"><?= e($task['title']) ?></a>
                        <span class="muted">
                            <?= $task['due_at'] ? e(t('tasks.due')) . ' ' . e(substr((string) $task['due_at'], 0, 10)) : e(t('tasks.no_due_date')) ?>
                            · <?= e(t('tasks.priority.' . $task['priority'])) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>

    <p style="margin-top:0.75rem"><a href="/tasks"><?= e(t('tasks.my_tasks')) ?> →</a></p>
</section>
<?php endif; ?>

<?php if ($topFollowUps !== null): ?>
<section class="card">
    <h2><?= e(t('dashboard.my_followups')) ?></h2>

    <?php if ($topFollowUps === []): ?>
        <p class="muted"><?= e(t('followups.empty')) ?></p>
    <?php else: ?>
        <ul class="note-list">
            <?php foreach ($topFollowUps as $f): ?>
                <li>
                    <a href="/followups/<?= e($f['id']) ?>"><?= e($f['title']) ?></a>
                    <span class="muted">
                        <?= $f['due_at'] ? e(t('followups.due_at')) . ' ' . e(substr((string) $f['due_at'], 0, 16)) : e(t('followups.no_due_date')) ?>
                        · <?= e(t('followups.priority.' . $f['priority'])) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p style="margin-top:0.75rem"><a href="/followups"><?= e(t('followups.title')) ?> →</a></p>
</section>
<?php endif; ?>
