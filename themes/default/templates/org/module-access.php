<?php
/** @var array $module */
/** @var array $users */
/** @var array<int, string> $overrides */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($module['name']) ?> — <?= e(t('org.member_access')) ?></h1>
    <p class="muted"><?= e(t('org.module_access_hint')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('org.access')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $status = $overrides[(int) $user['id']] ?? 'default'; ?>
            <tr>
                <td><?= e(trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''))) ?></td>
                <td><?= $status === 'disabled' ? e(t('org.restricted')) : e(t('org.allowed_default')) ?></td>
                <td>
                    <?php if ($status === 'disabled'): ?>
                        <form method="post" action="/org/modules/<?= e($module['id']) ?>/access/<?= e($user['id']) ?>/allow" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('org.remove_restriction')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/org/modules/<?= e($module['id']) ?>/access/<?= e($user['id']) ?>/restrict" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('org.restrict')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <p><a href="/org/modules"><?= e(t('org.back_to_modules')) ?></a></p>
</section>
