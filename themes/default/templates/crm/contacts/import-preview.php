<?php
/** @var array $rows */
/** @var array $customFieldDefs */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$validCount = 0;
foreach ($rows as $r) {
    if ($r['errors'] === []) {
        $validCount++;
    }
}
?>
<section class="card">
    <h1><?= e(t('import.review_title')) ?></h1>
    <p class="muted">
        <?= count($rows) ?> <?= e(t('import.rows_found')) ?>, <?= $validCount ?> <?= e(t('import.ready_to_import')) ?>.
        <?= e(t('import.fix_hint')) ?>
    </p>

    <?php if ($rows === []): ?>
        <p class="muted"><?= e(t('import.no_rows_found')) ?></p>
        <p><a href="/contacts/import" class="btn"><?= e(t('org.cancel')) ?></a></p>
    <?php else: ?>

    <form method="post" action="/contacts/import/commit">
        <?= Csrf::field() ?>

        <div class="table-responsive">
        <table class="table table--compact">
            <thead>
                <tr>
                    <th></th>
                    <th><?= e(t('import.row')) ?></th>
                    <th><?= e(t('auth.first_name')) ?></th>
                    <th><?= e(t('auth.last_name')) ?></th>
                    <th><?= e(t('common.email')) ?></th>
                    <th><?= e(t('common.phone')) ?></th>
                    <th><?= e(t('common.company')) ?></th>
                    <th><?= e(t('common.tags')) ?></th>
                    <th><?= e(t('import.action')) ?></th>
                    <th><?= e(t('org.label')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $row): ?>
                <?php $hasErrors = $row['errors'] !== []; ?>
                <tr<?= $hasErrors ? ' class="row--danger"' : '' ?>>
                    <td>
                        <input type="checkbox" name="rows[<?= e($i) ?>][include]" value="1" <?= !$hasErrors ? 'checked' : '' ?>>
                    </td>
                    <td><?= e($row['row_number']) ?></td>
                    <td><input type="text" name="rows[<?= e($i) ?>][data][first_name]" value="<?= e($row['data']['first_name']) ?>" style="width:110px"></td>
                    <td><input type="text" name="rows[<?= e($i) ?>][data][last_name]" value="<?= e($row['data']['last_name']) ?>" style="width:110px"></td>
                    <td><input type="email" name="rows[<?= e($i) ?>][data][email]" value="<?= e($row['data']['email']) ?>" style="width:160px"></td>
                    <td><input type="text" name="rows[<?= e($i) ?>][data][phone]" value="<?= e($row['data']['phone']) ?>" style="width:110px"></td>
                    <td><input type="text" name="rows[<?= e($i) ?>][data][company]" value="<?= e($row['data']['company']) ?>" style="width:130px"></td>
                    <td><input type="text" name="rows[<?= e($i) ?>][data][tags]" value="<?= e($row['data']['tags']) ?>" style="width:110px"></td>
                    <td>
                        <?= $row['action'] === 'update' ? e(t('import.update_hash')) . e($row['existing_id']) : e(t('import.create_new')) ?>
                        <input type="hidden" name="rows[<?= e($i) ?>][existing_id]" value="<?= e($row['existing_id'] ?? '') ?>">
                    </td>
                    <td class="muted">
                        <?php foreach ($row['errors'] as $err): ?><div class="text-danger"><?= e($err) ?></div><?php endforeach; ?>
                        <?php if ($row['company_note']): ?><div><?= e($row['company_note']) ?></div><?php endif; ?>
                        <?php foreach (['position', 'address', 'city', 'country', 'source', 'status'] as $hidden): ?>
                            <input type="hidden" name="rows[<?= e($i) ?>][data][<?= e($hidden) ?>]" value="<?= e($row['data'][$hidden]) ?>">
                        <?php endforeach; ?>
                        <?php foreach ($row['custom'] as $key => $value): ?>
                            <input type="hidden" name="rows[<?= e($i) ?>][custom][<?= e($key) ?>]" value="<?= e($value) ?>">
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p>
            <button type="submit" class="btn btn--primary"><?= icon('check') ?><?= e(t('import.import_checked')) ?></button>
            <a href="/contacts/import" class="btn"><?= e(t('org.cancel')) ?></a>
        </p>
    </form>
    <?php endif; ?>
</section>
