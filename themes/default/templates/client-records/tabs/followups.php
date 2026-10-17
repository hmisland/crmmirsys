<?php
/**
 * Follow-ups tab. Every follow-up shown or created here lives in the
 * Follow-ups module (related to this client's contact); this tab only
 * lists them and posts the quick form, which the Follow-ups service
 * validates and records. Variables come from show.php's scope.
 *
 * @var array $followUps
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_now;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$open = array_filter($followUps, fn ($f) => in_array($f['status'], ['pending', 'scheduled', 'in_progress'], true));
$closed = array_filter($followUps, fn ($f) => !in_array($f['status'], ['pending', 'scheduled', 'in_progress'], true));
$sessionByFollowUp = [];
foreach ($sessions as $s) {
    if ($s['follow_up_id'] !== null) {
        $sessionByFollowUp[(int) $s['follow_up_id']] = $s;
    }
}
$now = local_now(); // due_at is local wall clock
$groups = ['open' => $open, 'closed' => $closed];
?>
<div class="record-layout">
    <div>
        <section class="card">
            <h2><?= icon('calendar-check') ?> <?= e(t('client_records.tab.followups')) ?></h2>
            <?php foreach ($groups as $key => $rows): ?>
                <div class="task-group">
                    <h3 class="task-group__title"><?= e(t('client_records.followups_' . $key)) ?> <span class="count"><?= e(count($rows)) ?></span></h3>
                    <ul class="item-list">
                        <?php foreach ($rows as $f): ?>
                            <?php $overdue = $key === 'open' && $f['due_at'] !== null && $f['due_at'] < $now; ?>
                            <li>
                                <span class="dot dot--<?= e($f['priority']) ?>" aria-hidden="true"></span>
                                <div class="item-list__main">
                                    <a href="/followups/<?= e($f['id']) ?>"><?= e($f['title']) ?></a>
                                    <span class="item-list__meta">
                                        <?= e(t('followups.type.' . $f['type'])) ?> ·
                                        <span class="ltr<?= $overdue ? ' text-danger' : '' ?>"><?= e($f['due_at'] !== null ? substr($f['due_at'], 0, 10) : t('followups.no_due_date')) ?></span>
                                        <?php if (!empty($f['assignee_name'])): ?> · <?= e(trim($f['assignee_name'])) ?><?php endif; ?>
                                        <?php if (isset($sessionByFollowUp[(int) $f['id']])): ?> · <?= icon('activity', 'icon--sm') ?> <?= e(t('client_records.from_session')) ?><?php endif; ?>
                                    </span>
                                </div>
                                <?= badge(t('followups.status.' . $f['status']), $f['status']) ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?><li class="item-list--empty"><?= e(t('client_records.no_followups')) ?></li><?php endif; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <div>
        <?php if ($can['create_followup']): ?>
            <section class="card card--form" id="new-followup">
                <h2><?= e(t('client_records.new_followup')) ?></h2>
                <form method="post" action="/client-records/<?= e($record['id']) ?>/followups">
                    <?= Csrf::field() ?>
                    <label for="fu_title"><?= e(t('client_records.followup_title')) ?></label>
                    <input type="text" id="fu_title" name="title" required placeholder="<?= e(t('client_records.followup_title_placeholder')) ?>">

                    <label for="fu_type"><?= e(t('followups.type')) ?></label>
                    <select id="fu_type" name="type">
                        <?php foreach (['call', 'message', 'meeting', 'email', 'reminder', 'task'] as $type): ?>
                            <option value="<?= e($type) ?>"><?= e(t('followups.type.' . $type)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="fu_due"><?= e(t('followups.due_at')) ?></label>
                    <input type="text" class="crm-datepicker" id="fu_due" name="due_at">

                    <label for="fu_priority"><?= e(t('followups.priority')) ?></label>
                    <select id="fu_priority" name="priority">
                        <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === 'normal' ? 'selected' : '' ?>><?= e(t('followups.priority.' . $p)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="fu_assignee"><?= e(t('followups.assignee')) ?></label>
                    <select id="fu_assignee" name="assignee_user_id">
                        <option value=""><?= e(t('common.none')) ?></option>
                        <?php foreach ($users as $uid => $uname): ?>
                            <option value="<?= e($uid) ?>" <?= (string) $uid === (string) ($record['assigned_user_id'] ?? '') ? 'selected' : '' ?>><?= e($uname) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="fu_description"><?= e(t('client_records.followup_description')) ?></label>
                    <textarea id="fu_description" name="description" rows="2"></textarea>

                    <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('client_records.create_followup')) ?></button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
