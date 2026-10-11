<?php
/**
 * One appointment: facts, notes and status actions. View-model data
 * from AppointmentController::show().
 *
 * @var array $appointment
 * @var array<int, string> $users
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var bool $canEdit
 * @var bool $canDelete
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$a = $appointment;
$id = (int) $a['id'];
$base = '/appointments/' . $id;
$who = !empty($a['contact_id']) ? trim($a['contact_first_name'] . ' ' . ($a['contact_last_name'] ?? '')) : t('appointments.internal_meeting');
$what = (string) ($a['title'] ?? '') !== '' ? (string) $a['title'] : (string) ($a['type_name'] ?? '');
$minutes = intdiv(strtotime((string) $a['ends_at']) - strtotime((string) $a['starts_at']), 60);
$day = substr((string) $a['starts_at'], 0, 10);

// Next steps offered from each status; anything else is reachable from the edit form.
$transitions = [
    'requested' => ['confirmed'],
    'scheduled' => ['confirmed', 'in_progress', 'completed', 'no_show'],
    'confirmed' => ['in_progress', 'completed', 'no_show'],
    'in_progress' => ['completed'],
    'completed' => ['scheduled'],
    'cancelled' => ['scheduled'],
    'no_show' => ['scheduled'],
];
$actionIcons = ['confirmed' => 'check', 'in_progress' => 'activity', 'completed' => 'check-circle', 'no_show' => 'ban', 'scheduled' => 'undo'];
$canCancel = !in_array($a['status'], ['cancelled', 'completed', 'no_show'], true);
?>
<nav class="text-sm" aria-label="<?= e(t('appointments.back')) ?>">
    <a href="/appointments?date=<?= e($day) ?>"><?= icon('arrow-left', 'icon--sm') ?> <?= e(t('appointments.title')) ?></a>
</nav>

<section class="card">
    <div class="page-head">
        <div>
            <h1 class="profile-head__title">
                <span><?= e($what !== '' ? $what : t('appointments.appointment')) ?></span>
                <?= badge(t('appointments.status.' . $a['status']), $a['status']) ?>
            </h1>
            <p class="profile-head__sub">
                <span><?= icon('calendar') ?><?= e(t('appointments.weekday.' . date('w', strtotime($day))) . ' ' . $fmt->date($day)) ?></span>
                <span><?= icon('clock') ?><span class="ltr"><?= e($fmt->digits(substr((string) $a['starts_at'], 11, 5) . '–' . substr((string) $a['ends_at'], 11, 5))) ?></span></span>
            </p>
        </div>
        <?php if ($canEdit || $canDelete): ?>
            <div class="actions">
                <?php if ($canEdit): ?>
                    <a href="<?= e($base) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('appointments.edit')) ?></a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="post" action="<?= e($base) ?>/delete">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('appointments.delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('user') ?><?= e(t('appointments.customer')) ?></dt>
            <dd><?php if (!empty($a['contact_id'])): ?><a href="/contacts/<?= e($a['contact_id']) ?>"><?= e($who) ?></a><?php else: ?><?= e($who) ?><?php endif; ?></dd>
        </div>
        <?php if (!empty($a['contact_phone'])): ?>
            <div class="fact">
                <dt><?= icon('phone') ?><?= e(t('common.phone')) ?></dt>
                <dd><a href="tel:<?= e($a['contact_phone']) ?>" class="ltr"><?= e($a['contact_phone']) ?></a></dd>
            </div>
        <?php endif; ?>
        <div class="fact">
            <dt><?= icon('layers') ?><?= e(t('appointments.resource')) ?></dt>
            <dd><?= e($a['resource_name'] ?? t('appointments.no_resource')) ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('users') ?><?= e(t('appointments.assigned_user')) ?></dt>
            <dd><?= e($a['assigned_user_id'] !== null ? ($users[$a['assigned_user_id']] ?? '—') : '—') ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('tag') ?><?= e(t('appointments.type')) ?></dt>
            <dd><?= e($a['type_name'] ?? '—') ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('history') ?><?= e(t('appointments.length')) ?></dt>
            <dd><?= e($fmt->digits(t('appointments.minutes_n', ['n' => $minutes]))) ?></dd>
        </div>
        <?php if ($a['source'] !== 'staff'): ?>
            <div class="fact">
                <dt><?= icon('globe') ?><?= e(t('appointments.source')) ?></dt>
                <dd><?= e(t('appointments.source.' . $a['source'])) ?></dd>
            </div>
        <?php endif; ?>
    </dl>
</section>

<?php if (!empty($a['notes']) || ($a['status'] === 'cancelled' && !empty($a['cancel_reason']))): ?>
    <section class="card">
        <?php if (!empty($a['notes'])): ?>
            <h2><?= e(t('appointments.notes')) ?></h2>
            <p class="prose-text"><?= e($a['notes']) ?></p>
        <?php endif; ?>
        <?php if ($a['status'] === 'cancelled' && !empty($a['cancel_reason'])): ?>
            <h2><?= e(t('appointments.cancel_reason')) ?></h2>
            <p class="prose-text"><?= e($a['cancel_reason']) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canEdit): ?>
    <section class="card">
        <h2><?= e(t('appointments.change_status')) ?></h2>
        <div class="actions">
            <?php foreach ($transitions[$a['status']] ?? [] as $next): ?>
                <form method="post" action="<?= e($base) ?>/status">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="status" value="<?= e($next) ?>">
                    <button type="submit" class="btn<?= $next === 'completed' ? ' btn--primary' : '' ?>"><?= icon($actionIcons[$next]) ?><?= e(t('appointments.action.' . $next)) ?></button>
                </form>
            <?php endforeach; ?>
        </div>

        <?php if ($canCancel): ?>
            <form method="post" action="<?= e($base) ?>/status" class="inline-form-row appt-cancel">
                <?= Csrf::field() ?>
                <input type="hidden" name="status" value="cancelled">
                <input type="text" name="cancel_reason" maxlength="255" placeholder="<?= e(t('appointments.cancel_reason_placeholder')) ?>" aria-label="<?= e(t('appointments.cancel_reason')) ?>">
                <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('appointments.cancel_confirm')) ?>"><?= icon('x-circle') ?><?= e(t('appointments.action.cancelled')) ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
