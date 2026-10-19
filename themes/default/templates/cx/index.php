<?php
/**
 * Customer Satisfaction workspace: surveys, open follow-ups, recent
 * responses and invitations.
 *
 * @var array[] $surveys
 * @var array[] $invitations
 * @var array[] $responses
 * @var array[] $followUps
 * @var bool $canManage
 * @var bool $deliveryAvailable a delivery channel (Marketing) is installed
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$sentimentTone = fn (?string $s): string => match ($s) {
    'satisfied', 'promoter' => 'active',
    'dissatisfied', 'detractor' => 'failed',
    'neutral', 'passive' => 'pending',
    default => 'neutral',
};
?>
<div class="page-head">
    <div>
        <h1><?= e(t('cx.title')) ?></h1>
        <p><?= e(t('cx.subtitle')) ?></p>
    </div>
    <?php if ($canManage): ?>
    <div class="actions">
        <a href="/cx/surveys/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('cx.new_survey')) ?></a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$deliveryAvailable): ?>
    <div class="alert alert--warning"><?= e(t('cx.delivery_not_available')) ?></div>
<?php endif; ?>

<section class="card">
    <h2><?= icon('smile') ?><?= e(t('cx.surveys')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('cx.field.name')) ?></th>
                <th><?= e(t('cx.field.type')) ?></th>
                <th><?= e(t('cx.field.trigger')) ?></th>
                <th><?= e(t('cx.field.status')) ?></th>
                <th class="num"><?= e(t('cx.invitations')) ?></th>
                <th class="num"><?= e(t('cx.responses')) ?></th>
                <th class="num"><?= e(t('cx.average_score')) ?></th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($surveys as $s): ?>
                <tr>
                    <td><?= e($s['name']) ?></td>
                    <td><?= e(t('cx.type.' . $s['type'])) ?></td>
                    <td><?= e($s['trigger_event'] !== null ? t('cx.trigger.' . $s['trigger_event']) : t('cx.trigger.none')) ?></td>
                    <td><?= badge(t('cx.status.' . $s['status']), $s['status']) ?></td>
                    <td class="num"><?= e((string) (int) $s['invitation_count']) ?></td>
                    <td class="num"><?= e((string) (int) $s['response_count']) ?></td>
                    <td class="num"><?= e($s['average_score'] !== null ? number_format((float) $s['average_score'], 1) : '—') ?></td>
                    <?php if ($canManage): ?>
                        <td><a href="/cx/surveys/<?= e($s['id']) ?>/edit" class="btn btn--sm"><?= icon('pencil') ?><?= e(t('cx.edit')) ?></a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($surveys === []): ?>
                <tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="muted"><?= e(t('cx.no_surveys')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="follow-ups">
    <h2><?= icon('alert-triangle') ?><?= e(t('cx.follow_ups')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('cx.submitted_at')) ?></th>
                <th><?= e(t('cx.customer')) ?></th>
                <th><?= e(t('cx.score')) ?></th>
                <th><?= e(t('cx.comment')) ?></th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($followUps as $r): ?>
                <tr>
                    <td><span class="ltr"><?= e(local_time($r['submitted_at']) ?? '') ?></span></td>
                    <td>
                        <?php if (!empty($r['contact_id'])): ?>
                            <a href="/contacts/<?= e($r['contact_id']) ?>"><?= e($r['recipient_name'] ?? '—') ?></a>
                        <?php else: ?>
                            <?= e($r['recipient_name'] ?? '—') ?>
                        <?php endif; ?>
                        <?php if (!empty($r['context_label'])): ?><div class="muted"><?= e(t('cx.order_label', ['order' => (string) $r['context_label']])) ?></div><?php endif; ?>
                    </td>
                    <td><?= badge((string) $r['score'] . ' · ' . t('cx.sentiment.' . $r['sentiment']), $sentimentTone($r['sentiment'])) ?></td>
                    <td><?= e($r['comment'] ?? '') ?></td>
                    <?php if ($canManage): ?>
                        <td>
                            <form method="post" action="/cx/responses/<?= e($r['id']) ?>/follow-up">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--sm"><?= icon('check') ?><?= e(t('cx.close_follow_up')) ?></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($followUps === []): ?>
                <tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="muted"><?= e(t('cx.no_follow_ups')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2><?= icon('message') ?><?= e(t('cx.recent_responses')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('cx.submitted_at')) ?></th>
                <th><?= e(t('cx.survey')) ?></th>
                <th><?= e(t('cx.customer')) ?></th>
                <th><?= e(t('cx.score')) ?></th>
                <th><?= e(t('cx.comment')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($responses as $r): ?>
                <tr>
                    <td><span class="ltr"><?= e(local_time($r['submitted_at']) ?? '') ?></span></td>
                    <td><?= e($r['survey_name']) ?></td>
                    <td><?= e($r['recipient_name'] ?? '—') ?></td>
                    <td><?= $r['score'] !== null ? badge((string) $r['score'] . ' · ' . t('cx.sentiment.' . $r['sentiment']), $sentimentTone($r['sentiment'])) : '—' ?></td>
                    <td><?= e($r['comment'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($responses === []): ?>
                <tr><td colspan="5" class="muted"><?= e(t('cx.no_responses')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2><?= icon('send') ?><?= e(t('cx.recent_invitations')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('cx.survey')) ?></th>
                <th><?= e(t('cx.customer')) ?></th>
                <th><?= e(t('cx.field.status')) ?></th>
                <th><?= e(t('cx.field.channel')) ?></th>
                <th><?= e(t('cx.send_after')) ?></th>
                <th><?= e(t('cx.sent_at')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($invitations as $i): ?>
                <tr>
                    <td><?= e($i['survey_name']) ?><?php if (!empty($i['context_label'])): ?><div class="muted"><?= e(t('cx.order_label', ['order' => (string) $i['context_label']])) ?></div><?php endif; ?></td>
                    <td><?= e($i['recipient_name'] ?? '—') ?></td>
                    <td>
                        <?= badge(t('cx.invitation_status.' . $i['status']), $i['status']) ?>
                        <?php if (!empty($i['last_error'])): ?><div class="muted"><?= e(t((string) $i['last_error'])) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($i['channel'] !== null ? t('cx.channel.' . $i['channel']) : '—') ?></td>
                    <td><span class="ltr"><?= e(local_time($i['send_after']) ?? '') ?></span></td>
                    <td><span class="ltr"><?= e(local_time($i['sent_at']) ?? '—') ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invitations === []): ?>
                <tr><td colspan="6" class="muted"><?= e(t('cx.no_invitations')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
