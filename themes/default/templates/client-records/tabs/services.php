<?php
/**
 * Services tab: one card per service course with its session progress
 * and links to the catalogue product and invoice it came from.
 * Variables come from show.php's scope.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$invoicesById = array_column($invoices, null, 'id');
?>
<section class="card">
    <div class="card__header">
        <h2><?= icon('package') ?><?= e(t('client_records.tab.services')) ?></h2>
        <?php if ($can['sessions']): ?>
            <a href="/client-records/<?= e($record['id']) ?>/services/create" class="btn btn--primary btn--sm"><?= icon('plus') ?><?= e(t('client_records.new_service')) ?></a>
        <?php endif; ?>
    </div>

    <?php if ($services === []): ?>
        <div class="empty-state">
            <span class="empty-state__icon"><?= icon('package') ?></span>
            <p><?= e(t('client_records.no_services')) ?></p>
        </div>
    <?php else: ?>
        <div class="course-list">
            <?php foreach ($services as $service): ?>
                <?php
                $courseSessions = array_filter($sessions, fn ($s) => (int) $s['service_record_id'] === (int) $service['id']);
                $done = count(array_filter($courseSessions, fn ($s) => $s['status'] === 'completed'));
                $planned = (int) ($service['planned_sessions'] ?? 0);
                $invoice = $service['invoice_id'] !== null ? ($invoicesById[$service['invoice_id']] ?? null) : null;
                ?>
                <article class="card course">
                    <div class="course__head">
                        <h3 class="course__title"><?= e($service['title']) ?></h3>
                        <?= badge(t('client_records.service_status.' . $service['status']), $service['status']) ?>
                    </div>
                    <span class="item-list__meta">
                        <?= e($planned > 0 ? t('client_records.sessions_progress', ['done' => $done, 'planned' => $planned]) : t('client_records.sessions_done', ['done' => $done])) ?>
                    </span>
                    <?php if ($planned > 0): ?>
                        <progress class="progress<?= $done >= $planned ? ' progress--done' : '' ?>" value="<?= e(min($done, $planned)) ?>" max="<?= e($planned) ?>" aria-label="<?= e(t('client_records.sessions_progress', ['done' => $done, 'planned' => $planned])) ?>"></progress>
                    <?php endif; ?>
                    <dl class="detail-list">
                        <dt><?= e(t('client_records.provider')) ?></dt>
                        <dd><?= e($users[$service['provider_user_id']] ?? '—') ?></dd>
                        <?php if (!empty($service['location'])): ?>
                            <dt><?= e(t('client_records.location')) ?></dt>
                            <dd><?= e($service['location']) ?></dd>
                        <?php endif; ?>
                        <dt><?= e(t('client_records.period')) ?></dt>
                        <dd class="nowrap"><span class="ltr"><?= e($service['started_on'] ?? '—') ?></span> – <span class="ltr"><?= e($service['completed_on'] ?? '…') ?></span></dd>
                        <?php if ($service['invoice_id'] !== null): ?>
                            <dt><?= e(t('client_records.invoice')) ?></dt>
                            <dd><?php if ($invoice !== null): ?><a href="/finance/invoices/<?= e($invoice['id']) ?>"><?= e($invoice['invoice_number'] ?? '#' . $invoice['id']) ?></a><?php else: ?><span class="muted">#<?= e($service['invoice_id']) ?></span><?php endif; ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if (!empty($service['notes'])): ?>
                        <p class="prose-text text-sm"><?= e($service['notes']) ?></p>
                    <?php endif; ?>
                    <?php if ($can['sessions']): ?>
                        <div class="actions">
                            <a href="/client-records/<?= e($record['id']) ?>/sessions/create?service_id=<?= e($service['id']) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('client_records.add_session')) ?></a>
                            <a href="/client-records/<?= e($record['id']) ?>/services/<?= e($service['id']) ?>/edit" class="btn btn--ghost btn--sm"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                            <form method="post" action="/client-records/<?= e($record['id']) ?>/services/<?= e($service['id']) ?>/delete" class="inline-form">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('client_records.delete_service_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
