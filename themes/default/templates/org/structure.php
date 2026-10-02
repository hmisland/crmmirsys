<?php
/** @var array $departments */
/** @var array $teams */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('org.departments')) ?></h1>

    <div class="table-responsive"><table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($departments as $department): ?>
            <tr>
                <td><?= e($department['name']) ?></td>
                <td>
                    <form method="post" action="/org/structure/departments/<?= e($department['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($departments === []): ?>
            <tr><td colspan="2" class="muted"><?= e(t('org.no_departments_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>

    <form method="post" action="/org/structure/departments" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="text" name="name" placeholder="<?= e(t('org.new_department_name')) ?>" required>
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('org.add_department')) ?></button>
    </form>
</section>

<section class="card">
    <h1><?= e(t('org.teams')) ?></h1>

    <div class="table-responsive"><table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('org.department')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teams as $team): ?>
            <?php
                $departmentName = '—';
                foreach ($departments as $d) {
                    if ((int) $d['id'] === (int) $team['department_id']) {
                        $departmentName = $d['name'];
                    }
                }
            ?>
            <tr>
                <td><?= e($team['name']) ?></td>
                <td><?= e($departmentName) ?></td>
                <td>
                    <form method="post" action="/org/structure/teams/<?= e($team['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($teams === []): ?>
            <tr><td colspan="3" class="muted"><?= e(t('org.no_teams_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>

    <form method="post" action="/org/structure/teams" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="text" name="name" placeholder="<?= e(t('org.new_team_name')) ?>" required>
        <select aria-label="<?= e(t('org.department')) ?>" name="department_id">
            <option value=""><?= e(t('org.no_department')) ?></option>
            <?php foreach ($departments as $department): ?>
                <option value="<?= e($department['id']) ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('org.add_team')) ?></button>
    </form>
</section>
