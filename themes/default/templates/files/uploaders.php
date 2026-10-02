<?php
/** @var array $users */
/** @var array $grantedUserIds */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('files.manage_upload_access')) ?></h1>
    <p class="muted"><?= e(t('files.uploaders_hint')) ?></p>
    <p><a href="/files" class="btn"><?= e(t('files.back_to_files')) ?></a></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.member')) ?></th><th><?= e(t('files.can_upload_delete')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $granted = in_array((int) $user['id'], $grantedUserIds, true); ?>
            <tr>
                <td><?= e(trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''))) ?></td>
                <td><?= $granted ? e(t('common.yes')) : e(t('common.no')) ?></td>
                <td>
                    <?php if ($granted): ?>
                        <form method="post" action="/files/uploaders/<?= e($user['id']) ?>/revoke" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('files.revoke')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/files/uploaders/grant" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= e($user['id']) ?>">
                            <button type="submit" class="link-button"><?= e(t('files.grant')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($users === []): ?>
            <tr><td colspan="3" class="muted"><?= e(t('files.no_other_members_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
