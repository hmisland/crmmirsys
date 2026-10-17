<?php
/**
 * Overview tab — "understand this client in ten seconds": latest
 * service, next visit, last follow-up, recent activity, key info and
 * notes. Variables come from show.php's scope.
 *
 * @var array $followUps
 * @var array $timeline
 * @var array $notes
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$latest = $summary['latest_service'];
$next = $summary['next_visit'];
$lastFollowUp = null;
foreach ($followUps as $f) {
    if ($lastFollowUp === null || (string) $f['due_at'] > (string) $lastFollowUp['due_at']) {
        $lastFollowUp = $f;
    }
}
$doneFor = function (array $service) use ($sessions): int {
    return count(array_filter($sessions, fn ($s) => (int) $s['service_record_id'] === (int) $service['id'] && $s['status'] === 'completed'));
};
?>
<div class="record-layout">
    <div>
        <div class="kpi-grid">
            <section class="card kpi">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e(t('client_records.latest_service')) ?></span>
                    <span class="kpi__icon"><?= icon('package') ?></span>
                </span>
                <?php if ($latest !== null): ?>
                    <?php $done = $doneFor($latest); $planned = (int) ($latest['planned_sessions'] ?? 0); ?>
                    <strong class="event__title"><?= e($latest['title']) ?></strong>
                    <span class="item-list__meta"><?= badge(t('client_records.service_status.' . $latest['status']), $latest['status']) ?>
                        <?= e($planned > 0 ? t('client_records.sessions_progress', ['done' => $done, 'planned' => $planned]) : t('client_records.sessions_done', ['done' => $done])) ?></span>
                    <?php if ($planned > 0): ?>
                        <progress class="progress<?= $done >= $planned ? ' progress--done' : '' ?>" value="<?= e(min($done, $planned)) ?>" max="<?= e($planned) ?>" aria-label="<?= e(t('client_records.sessions_progress', ['done' => $done, 'planned' => $planned])) ?>"></progress>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted"><?= e(t('client_records.no_services')) ?></span>
                <?php endif; ?>
            </section>

            <section class="card kpi">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e(t('client_records.next_visit')) ?></span>
                    <span class="kpi__icon kpi__icon--info"><?= icon('calendar') ?></span>
                </span>
                <?php if ($next !== null): ?>
                    <strong class="event__title nowrap"><span class="ltr"><?= e(substr($next['session_at'], 0, 16)) ?></span></strong>
                    <span class="item-list__meta"><?= e($next['service_record_id'] !== null ? ($servicesById[$next['service_record_id']]['title'] ?? '') : (string) $next['title']) ?></span>
                <?php else: ?>
                    <span class="muted"><?= e(t('client_records.no_upcoming')) ?></span>
                    <?php if ($can['sessions']): ?>
                        <a class="kpi__link" href="/client-records/<?= e($record['id']) ?>/sessions/create?status=scheduled"><?= e(t('client_records.schedule_visit')) ?><?= icon('arrow-right', 'icon--sm') ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($can['followups']): ?>
                <section class="card kpi">
                    <span class="kpi__top">
                        <span class="kpi__label"><?= e(t('client_records.last_followup')) ?></span>
                        <span class="kpi__icon kpi__icon--warning"><?= icon('calendar-check') ?></span>
                    </span>
                    <?php if ($lastFollowUp !== null): ?>
                        <a class="event__title" href="/followups/<?= e($lastFollowUp['id']) ?>"><?= e($lastFollowUp['title']) ?></a>
                        <span class="item-list__meta"><?= badge(t('followups.status.' . $lastFollowUp['status']), $lastFollowUp['status']) ?>
                            <span class="ltr"><?= e($lastFollowUp['due_at'] !== null ? substr($lastFollowUp['due_at'], 0, 10) : t('followups.no_due_date')) ?></span></span>
                    <?php else: ?>
                        <span class="muted"><?= e(t('client_records.no_followups')) ?></span>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <section class="card">
            <div class="card__header">
                <h2><?= icon('history') ?><?= e(t('client_records.recent_activity')) ?></h2>
                <a href="/client-records/<?= e($record['id']) ?>?tab=timeline" class="btn btn--ghost btn--sm"><?= e(t('client_records.full_timeline')) ?><?= icon('arrow-right', 'icon--sm') ?></a>
            </div>
            <?php $events = $timeline; include $theme->templatesPath() . '/client-records/_events.php'; ?>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card__header">
                <h2><?= icon('info') ?><?= e(t('client_records.key_information')) ?></h2>
                <?php if ($can['edit']): ?>
                    <a href="/client-records/<?= e($record['id']) ?>/edit" class="btn btn--ghost btn--sm"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                <?php endif; ?>
            </div>
            <dl class="detail-list">
                <dt><?= e(t('client_records.preferences')) ?></dt>
                <dd><?php if (!empty($record['preferences'])): ?><p class="prose-text"><?= e($record['preferences']) ?></p><?php else: ?><span class="muted">—</span><?php endif; ?></dd>
                <?php if ($can['sensitive']): ?>
                    <dt><?= e(t('client_records.restrictions')) ?></dt>
                    <dd><?php if (!empty($record['restrictions'])): ?><p class="prose-text"><?= e($record['restrictions']) ?></p><?php else: ?><span class="muted">—</span><?php endif; ?></dd>
                <?php endif; ?>
                <dt><?= e(t('client_records.source')) ?></dt>
                <dd><?= e($contact['source'] ?: '—') ?></dd>
                <dt><?= e(t('client_records.opened_on')) ?></dt>
                <dd><?= e(substr((string) local_time($record['created_at']), 0, 10)) ?></dd>
            </dl>
            <?php if (!$can['sensitive']): ?>
                <p class="locked"><?= icon('lock') ?><?= e(t('client_records.sensitive_locked')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card" id="notes">
            <h2><?= e(t('common.notes')) ?></h2>
            <?php if ($can['edit']): ?>
                <form method="post" action="/client-records/<?= e($record['id']) ?>/notes" class="form-stack">
                    <?= Csrf::field() ?>
                    <textarea name="body" rows="3" placeholder="<?= e(t('common.write_a_note')) ?>" aria-label="<?= e(t('common.notes')) ?>" required></textarea>
                    <button type="submit" class="btn btn--primary btn--sm"><?= icon('plus') ?><?= e(t('common.add_note')) ?></button>
                </form>
            <?php endif; ?>
            <ul class="note-list">
                <?php foreach ($notes as $note): ?>
                    <li>
                        <p class="prose-text"><?= e($note['body']) ?></p>
                        <span class="muted"><?= e(substr((string) local_time($note['created_at']), 0, 16)) ?><?= isset($users[$note['user_id']]) ? ' · ' . e($users[$note['user_id']]) : '' ?></span>
                        <?php if ($can['edit']): ?>
                            <form method="post" action="/client-records/<?= e($record['id']) ?>/notes/<?= e($note['id']) ?>/delete" class="inline-form">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($notes === []): ?><li class="muted"><?= e(t('common.no_notes_yet')) ?></li><?php endif; ?>
            </ul>
        </section>
    </div>
</div>
