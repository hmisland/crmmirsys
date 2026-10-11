<?php
/**
 * Client record page shell: profile header, alerts, tab bar, then the
 * active tab's partial from client-records/tabs/. Every value here is
 * view-model data from ClientRecordController::show(); sensitive fields
 * already arrive as null when the viewer may not see them.
 *
 * @var string $tab
 * @var array $record
 * @var array $contact
 * @var array<int, string> $users
 * @var array $services
 * @var array $sessions
 * @var array $invoices
 * @var array $summary
 * @var array<string, bool> $can
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$recordId = (int) $record['id'];
$base = '/client-records/' . $recordId;
$name = trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''));
$initial = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
$specialist = $record['assigned_user_id'] !== null ? ($users[$record['assigned_user_id']] ?? null) : null;

$money = fn (string $amount, ?string $code = null): string => \App\Infrastructure\Theme\money($amount, $code); // currency display policy (L2)
$visitLabel = function (?array $session): string {
    return $session === null ? '—' : substr((string) $session['session_at'], 0, 16);
};

$tabs = [
    'overview' => ['icon' => 'dashboard', 'label' => t('client_records.tab.overview')],
    'intake' => ['icon' => 'clipboard', 'label' => t('client_records.tab.intake')],
    'services' => ['icon' => 'package', 'label' => t('client_records.tab.services'), 'count' => count($services)],
    'sessions' => ['icon' => 'activity', 'label' => t('client_records.tab.sessions'), 'count' => count(array_filter($sessions, fn ($s) => $s['status'] !== 'scheduled'))],
    'appointments' => ['icon' => 'calendar', 'label' => t('client_records.tab.appointments'), 'count' => count(array_filter($sessions, fn ($s) => $s['status'] === 'scheduled'))],
];
if ($can['followups']) {
    $tabs['followups'] = ['icon' => 'calendar-check', 'label' => t('client_records.tab.followups')];
}
if ($can['invoices']) {
    $tabs['invoices'] = ['icon' => 'receipt', 'label' => t('client_records.tab.invoices'), 'count' => count($invoices)];
}
$tabs['documents'] = ['icon' => 'file-text', 'label' => t('client_records.tab.documents')];
$tabs['media'] = ['icon' => 'image', 'label' => t('client_records.tab.media')];
$tabs['timeline'] = ['icon' => 'history', 'label' => t('client_records.tab.timeline')];

if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
?>
<nav aria-label="<?= e(t('client_records.back')) ?>" class="text-sm">
    <a href="/client-records"><?= icon('arrow-left', 'icon--sm') ?> <?= e(t('client_records.title')) ?></a>
</nav>

<section class="card">
    <div class="profile-head">
        <span class="avatar avatar--lg" aria-hidden="true"><?= e($initial) ?></span>
        <div>
            <h1 class="profile-head__title">
                <span><?= e($name) ?></span>
                <?= badge(t('client_records.status.' . $record['status']), $record['status']) ?>
            </h1>
            <p class="profile-head__sub">
                <span><?= icon('clipboard') ?><span class="mono"><?= e($record['record_number']) ?></span></span>
                <?php if (!empty($contact['phone'])): ?>
                    <span><?= icon('phone') ?><a href="tel:<?= e($contact['phone']) ?>" class="ltr"><?= e($contact['phone']) ?></a></span>
                <?php endif; ?>
                <?php if (!empty($contact['email'])): ?>
                    <span><?= icon('mail') ?><a href="mailto:<?= e($contact['email']) ?>" class="ltr"><?= e($contact['email']) ?></a></span>
                <?php endif; ?>
                <span><?= icon('user') ?><?= e($specialist ?? t('client_records.no_specialist')) ?></span>
                <?php if ($can['contact']): ?>
                    <span><?= icon('users') ?><a href="/contacts/<?= e($contact['id']) ?>"><?= e(t('client_records.view_contact')) ?></a></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="actions">
            <?php if ($can['sessions']): ?>
                <a href="<?= e($base) ?>/sessions/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('client_records.new_session')) ?></a>
            <?php endif; ?>
            <?php if ($can['sessions'] || $can['create_followup'] || $can['edit'] || $can['media'] || $can['documents'] || $can['delete']): ?>
            <details class="dropdown">
                <summary class="btn"><?= icon('zap') ?><?= e(t('client_records.quick_actions')) ?><?= icon('chevron-down', 'icon--sm') ?></summary>
                <div class="dropdown__panel">
                    <?php if ($can['sessions']): ?>
                        <a class="dropdown__item" href="<?= e($base) ?>/services/create"><?= icon('package') ?><?= e(t('client_records.new_service')) ?></a>
                        <a class="dropdown__item" href="<?= e($base) ?>/sessions/create?status=scheduled"><?= icon('calendar') ?><?= e(t('client_records.schedule_visit')) ?></a>
                    <?php endif; ?>
                    <?php if ($can['create_followup']): ?>
                        <a class="dropdown__item" href="<?= e($base) ?>?tab=followups#new-followup"><?= icon('calendar-check') ?><?= e(t('client_records.new_followup')) ?></a>
                    <?php endif; ?>
                    <?php if ($can['edit']): ?>
                        <a class="dropdown__item" href="<?= e($base) ?>#notes"><?= icon('message') ?><?= e(t('client_records.add_note')) ?></a>
                    <?php endif; ?>
                    <?php if ($can['media']): ?>
                        <a class="dropdown__item" href="<?= e($base) ?>?tab=media#upload"><?= icon('image') ?><?= e(t('client_records.upload_media')) ?></a>
                    <?php endif; ?>
                    <?php if ($can['documents']): ?>
                        <a class="dropdown__item" href="<?= e($base) ?>?tab=documents#upload"><?= icon('upload') ?><?= e(t('client_records.upload_document')) ?></a>
                    <?php endif; ?>
                    <?php if ($can['edit']): ?>
                        <a class="dropdown__item" href="<?= e($base) ?>/edit"><?= icon('pencil') ?><?= e(t('client_records.edit_intake')) ?></a>
                        <form method="post" action="<?= e($base) ?>/status">
                            <?= Csrf::field() ?>
                            <?php if ($record['status'] === 'archived'): ?>
                                <input type="hidden" name="status" value="active">
                                <button type="submit" class="dropdown__item"><?= icon('undo') ?><?= e(t('client_records.restore')) ?></button>
                            <?php else: ?>
                                <input type="hidden" name="status" value="archived">
                                <button type="submit" class="dropdown__item" data-confirm="<?= e(t('client_records.archive_confirm')) ?>"><?= icon('archive') ?><?= e(t('client_records.archive')) ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if ($can['delete']): ?>
                        <form method="post" action="<?= e($base) ?>/delete">
                            <?= Csrf::field() ?>
                            <button type="submit" class="dropdown__item dropdown__item--danger" data-confirm="<?= e(t('client_records.delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('client_records.delete')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('history') ?><?= e(t('client_records.last_visit')) ?></dt>
            <dd><span class="ltr"><?= e($visitLabel($summary['last_visit'])) ?></span></dd>
        </div>
        <div class="fact">
            <dt><?= icon('calendar') ?><?= e(t('client_records.next_visit')) ?></dt>
            <dd><span class="ltr"><?= e($visitLabel($summary['next_visit'])) ?></span></dd>
        </div>
        <div class="fact">
            <dt><?= icon('package') ?><?= e(t('client_records.services_total')) ?></dt>
            <dd><?= e(t('client_records.services_count', ['active' => $summary['active_services'], 'total' => $summary['services_count']])) ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('activity') ?><?= e(t('client_records.sessions_completed')) ?></dt>
            <dd><?= e(number_format($summary['sessions_completed'])) ?></dd>
        </div>
        <?php if ($can['invoices']): ?>
            <div class="fact">
                <dt><?= icon('receipt') ?><?= e(t('client_records.outstanding')) ?></dt>
                <dd>
                    <?php if ($summary['outstanding'] === []): ?>
                        <span class="text-success"><?= e(t('client_records.nothing_due')) ?></span>
                    <?php else: ?>
                        <?php foreach ($summary['outstanding'] as $code => $amount): ?>
                            <span class="text-danger nowrap"><?= e($money($amount, (string) $code)) ?> <?= e($code) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </dd>
            </div>
        <?php endif; ?>
    </dl>
</section>

<?php if (!empty($record['important_notes'])): ?>
    <div class="alert alert--warning" role="note">
        <div class="alert__body"><strong><?= e(t('client_records.important_notes')) ?>:</strong>
            <p class="prose-text"><?= e($record['important_notes']) ?></p></div>
    </div>
<?php endif; ?>
<?php if ($can['sensitive'] && !empty($record['sensitivities'])): ?>
    <div class="alert alert--error" role="note">
        <div class="alert__body"><strong><?= e(t('client_records.sensitivities')) ?>:</strong>
            <p class="prose-text"><?= e($record['sensitivities']) ?></p></div>
    </div>
<?php endif; ?>

<nav class="card card--tabs" aria-label="<?= e(t('client_records.sections')) ?>">
    <div class="tabs">
        <?php foreach ($tabs as $key => $item): ?>
            <a class="tab-btn" href="<?= e($base) ?><?= $key === 'overview' ? '' : '?tab=' . e($key) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>>
                <?= icon($item['icon']) ?><?= e($item['label']) ?>
                <?php if (!empty($item['count'])): ?><span class="count"><?= e($item['count']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<?php include $theme->templatesPath() . '/client-records/tabs/' . $tab . '.php'; ?>
