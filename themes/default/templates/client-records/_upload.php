<?php
/**
 * Upload form shared by the Documents and Media tabs. Expects in scope:
 * $kind ('media'|'document'), $categories, $record, $services,
 * $sessions, $servicesById.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$categoryKey = $kind === 'media' ? 'client_records.media_category.' : 'client_records.doc_category.';
?>
<section class="card card--form" id="upload">
    <h2><?= e(t($kind === 'media' ? 'client_records.upload_media' : 'client_records.upload_document')) ?></h2>
    <form method="post" action="/client-records/<?= e($record['id']) ?>/files" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="kind" value="<?= e($kind) ?>">

        <label for="up_file"><?= e(t('client_records.file')) ?></label>
        <input type="file" id="up_file" name="file" required<?= $kind === 'media' ? ' accept="image/png,image/jpeg,image/gif,image/webp"' : '' ?>>
        <p class="field-hint"><?= e(t($kind === 'media' ? 'client_records.media_hint' : 'client_records.document_hint')) ?></p>

        <label for="up_category"><?= e(t('client_records.category')) ?></label>
        <select id="up_category" name="category">
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>"><?= e(t($categoryKey . $c)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="up_taken"><?= e(t('client_records.date')) ?></label>
        <input type="text" class="crm-datepicker" id="up_taken" name="taken_at" value="<?= e(local_today()) ?>">

        <?php if ($sessions !== []): ?>
            <label for="up_session"><?= e(t('client_records.linked_session')) ?></label>
            <select id="up_session" name="session_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($sessions as $s): ?>
                    <?php $label = ($s['service_record_id'] !== null ? ($servicesById[$s['service_record_id']]['title'] ?? '') : (string) $s['title']) . (!empty($s['session_number']) ? ' #' . $s['session_number'] : ''); ?>
                    <option value="<?= e($s['id']) ?>"><?= e(substr((string) $s['session_at'], 0, 10) . ' — ' . $label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($services !== []): ?>
            <label for="up_service"><?= e(t('client_records.service')) ?></label>
            <select id="up_service" name="service_record_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($services as $sv): ?>
                    <option value="<?= e($sv['id']) ?>"><?= e($sv['title']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <label for="up_notes"><?= e(t('client_records.notes_optional')) ?></label>
        <textarea id="up_notes" name="notes" rows="2" maxlength="500"></textarea>

        <button type="submit" class="btn btn--primary"><?= icon('upload') ?><?= e(t('common.upload')) ?></button>
    </form>
</section>
