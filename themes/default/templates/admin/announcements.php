<?php
/** @var array $sent */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var array $orgAdmins */
/** @var array $old */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$audience = ($old['audience'] ?? 'all') === 'single' ? 'single' : 'all';
?>
<section class="card">
    <h1><?= e(t('announcements.title')) ?></h1>
    <p class="muted"><?= e(t('announcements.subtitle')) ?></p>
</section>

<section class="card card--form">
    <h2><?= e(t('announcements.new')) ?></h2>
    <form method="post" action="/admin/announcements">
        <?= Csrf::field() ?>

        <fieldset class="announcement-audience">
            <legend><?= e(t('announcements.audience')) ?></legend>
            <label class="check check--spaced"><input type="radio" name="audience" value="all"<?= $audience === 'all' ? ' checked' : '' ?>> <?= e(t('announcements.audience_all', ['count' => count($orgAdmins)])) ?></label>
            <label class="check check--spaced"><input type="radio" name="audience" value="single"<?= $audience === 'single' ? ' checked' : '' ?>> <?= e(t('announcements.audience_single')) ?></label>
        </fieldset>

        <label for="recipient_user_id"><?= e(t('announcements.recipient')) ?></label>
        <select id="recipient_user_id" name="recipient_user_id">
            <option value=""><?= e(t('announcements.choose_recipient')) ?></option>
            <?php foreach ($orgAdmins as $a): ?>
                <option value="<?= e($a['id']) ?>"<?= (int) ($old['recipient_user_id'] ?? 0) === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['organization_name'] . ' — ' . trim($a['first_name'] . ' ' . ($a['last_name'] ?? '')) . ' (' . $a['email'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <p class="field-hint"><?= e(t('announcements.recipient_hint')) ?></p>

        <label for="announcement_title"><?= e(t('announcements.subject')) ?></label>
        <input type="text" id="announcement_title" name="title" maxlength="200" required value="<?= e($old['title'] ?? '') ?>">

        <label for="announcement_body"><?= e(t('announcements.body')) ?></label>
        <textarea id="announcement_body" name="body" rows="5" maxlength="5000" required><?= e($old['body'] ?? '') ?></textarea>

        <button type="submit" class="btn btn--primary" data-confirm="<?= e(t('announcements.send_confirm')) ?>"><?= icon('send') ?><?= e(t('announcements.send')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('announcements.sent_list')) ?></h2>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('announcements.subject')) ?></th>
                <th><?= e(t('announcements.date')) ?></th>
                <th><?= e(t('announcements.audience')) ?></th>
                <th><?= e(t('announcements.seen_count')) ?></th>
                <th><?= e(t('announcements.actions')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($sent === []): ?>
                <tr><td colspan="5" class="muted"><?= e(t('announcements.empty_sent')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($sent as $row): ?>
                <tr>
                    <td><a href="/admin/announcements/<?= e($row['id']) ?>"><?= e($row['title']) ?></a></td>
                    <td class="nowrap"><?= e($fmt->date(local_time($row['created_at']))) ?> <span class="muted ltr"><?= e($fmt->digits(substr((string) local_time($row['created_at']), 11, 5))) ?></span></td>
                    <td><?= e(t('announcements.audience_' . ($row['audience'] === 'single' ? 'single_short' : 'all_short'))) ?></td>
                    <td><?= e($fmt->digits((int) $row['seen'] . ' / ' . (int) $row['recipients'])) ?></td>
                    <td class="nowrap">
                        <a href="/admin/announcements/<?= e($row['id']) ?>" class="btn btn--sm"><?= icon('eye') ?><?= e(t('announcements.report')) ?></a>
                        <form method="post" action="/admin/announcements/<?= e($row['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm btn--danger" data-confirm="<?= e(t('announcements.delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('announcements.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
