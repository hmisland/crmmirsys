<?php
/** @var array $record */
/** @var array $contact */
/** @var array|null $session */
/** @var array $services */
/** @var array $invoices */
/** @var array<int, string> $users */
/** @var bool $canSensitive */
/** @var bool $canFollowUp */
/** @var array $old */
/** @var array $errors */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isEdit = $session !== null;
$val = fn (string $key): string => e((string) ($old[$key] ?? ''));
$err = fn (string $key): string => isset($errors[$key][0]) ? '<div class="field-error">' . e(t($errors[$key][0])) . '</div>' : '';
$selected = fn (string $key, $value): string => (string) ($old[$key] ?? '') === (string) $value ? ' selected' : '';
$scheduled = ($old['status'] ?? '') === 'scheduled';
$back = '/client-records/' . (int) $record['id'] . '?tab=' . ($scheduled ? 'appointments' : 'sessions');
$action = '/client-records/' . (int) $record['id'] . '/sessions' . ($isEdit ? '/' . (int) $session['id'] : '');
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'client_records.edit_session' : ($scheduled ? 'client_records.schedule_visit' : 'client_records.new_session'))) ?></h1>
        <p><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?> · <span class="mono"><?= e($record['record_number']) ?></span></p>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('client_records.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <div class="form-grid">
            <div>
                <label for="service_record_id"><?= e(t('client_records.service')) ?></label>
                <select id="service_record_id" name="service_record_id">
                    <option value=""><?= e(t('client_records.standalone_visit')) ?></option>
                    <?php foreach ($services as $sv): ?>
                        <option value="<?= e($sv['id']) ?>"<?= $selected('service_record_id', $sv['id']) ?>><?= e($sv['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('service_record_id') ?>
            </div>
            <div>
                <label for="title"><?= e(t('client_records.visit_title')) ?></label>
                <input type="text" id="title" name="title" value="<?= $val('title') ?>" maxlength="255" placeholder="<?= e(t('client_records.visit_title_placeholder')) ?>">
                <?= $err('title') ?>
            </div>
            <div>
                <label for="session_date"><?= e(t('client_records.date')) ?></label>
                <input type="text" class="crm-datepicker" id="session_date" name="session_date" value="<?= $val('session_date') ?>" required>
                <?= $err('session_date') ?>
            </div>
            <div>
                <label for="session_time"><?= e(t('client_records.time')) ?></label>
                <input type="time" id="session_time" name="session_time" value="<?= $val('session_time') ?>" class="field-sm">
                <?= $err('session_time') ?>
            </div>
            <div>
                <label for="status"><?= e(t('client_records.status')) ?></label>
                <select id="status" name="status">
                    <?php foreach (['completed', 'scheduled', 'no_show', 'cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>"<?= $selected('status', $s) ?>><?= e(t('client_records.session_status.' . $s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('status') ?>
            </div>
            <div>
                <label for="session_number"><?= e(t('client_records.session_number')) ?></label>
                <input type="number" id="session_number" name="session_number" min="1" max="999" value="<?= $val('session_number') ?>">
                <?= $err('session_number') ?>
            </div>
            <div>
                <label for="provider_user_id"><?= e(t('client_records.provider')) ?></label>
                <select id="provider_user_id" name="provider_user_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($users as $uid => $name): ?>
                        <option value="<?= e($uid) ?>"<?= $selected('provider_user_id', $uid) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('provider_user_id') ?>
            </div>
            <?php if ($invoices !== []): ?>
                <div>
                    <label for="invoice_id"><?= e(t('client_records.related_invoice')) ?></label>
                    <select id="invoice_id" name="invoice_id">
                        <option value=""><?= e(t('common.none')) ?></option>
                        <?php foreach ($invoices as $inv): ?>
                            <option value="<?= e($inv['id']) ?>"<?= $selected('invoice_id', $inv['id']) ?>><?= e(($inv['invoice_number'] ?? '#' . $inv['id']) . ' — ' . $inv['issue_date']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('invoice_id') ?>
                </div>
            <?php endif; ?>
        </div>

        <h2 class="form-section-title"><?= e(t('client_records.section_session_notes')) ?></h2>
        <label for="notes"><?= e(t('client_records.session_notes')) ?></label>
        <textarea id="notes" name="notes" rows="4"><?= $val('notes') ?></textarea>

        <?php if ($canSensitive): ?>
            <label for="observations"><?= icon('lock', 'icon--sm') ?> <?= e(t('client_records.observations')) ?></label>
            <textarea id="observations" name="observations" rows="3"><?= $val('observations') ?></textarea>
        <?php endif; ?>

        <label for="client_feedback"><?= e(t('client_records.client_feedback')) ?></label>
        <textarea id="client_feedback" name="client_feedback" rows="2"><?= $val('client_feedback') ?></textarea>

        <h2 class="form-section-title"><?= e(t('client_records.section_next_steps')) ?></h2>
        <div class="form-grid">
            <div>
                <label for="next_recommended_on"><?= e(t('client_records.next_recommended')) ?></label>
                <input type="text" class="crm-datepicker" id="next_recommended_on" name="next_recommended_on" value="<?= $val('next_recommended_on') ?>">
                <?= $err('next_recommended_on') ?>
            </div>
            <?php if ($canFollowUp): ?>
                <div>
                    <label for="follow_up_on"><?= e(t('client_records.follow_up_on')) ?></label>
                    <input type="text" class="crm-datepicker" id="follow_up_on" name="follow_up_on" value="<?= $val('follow_up_on') ?>">
                    <?= $err('follow_up_on') ?>
                </div>
                <div>
                    <label for="follow_up_title"><?= e(t('client_records.followup_title')) ?></label>
                    <input type="text" id="follow_up_title" name="follow_up_title" value="<?= $val('follow_up_title') ?>" maxlength="255" placeholder="<?= e(t('client_records.followup_title_placeholder')) ?>">
                </div>
            <?php elseif ($isEdit && $session['follow_up_id'] !== null): ?>
                <div>
                    <span class="form-label"><?= e(t('client_records.linked_followup')) ?></span>
                    <a href="/followups/<?= e($session['follow_up_id']) ?>"><?= icon('calendar-check', 'icon--sm') ?> #<?= e($session['follow_up_id']) ?></a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($canFollowUp): ?>
            <p class="field-hint"><?= e(t('client_records.follow_up_hint')) ?></p>
        <?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
