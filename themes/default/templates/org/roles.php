<?php
/** @var array $roles */
/** @var string[] $allPermissions */
/** @var array|null $editingRole */
/** @var array $editingPermissions */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$formAction = $editingRole !== null ? '/org/roles/' . $editingRole['id'] : '/org/roles';
$nameValue = $old['name'] ?? ($editingRole['name'] ?? '');
$scopes = ['own', 'team', 'department', 'organization', 'all'];
?>
<section class="card">
    <h1><?= e(t('org.roles')) ?></h1>

    <div class="table-responsive"><table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('org.type')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $role): ?>
            <tr>
                <td><?= e($role['name']) ?></td>
                <td><?= $role['is_system'] ? e(t('org.system_template')) : e(t('org.custom')) ?></td>
                <td>
                    <?php if (!$role['is_system']): ?>
                        <a href="/org/roles?edit=<?= e($role['id']) ?>"><?= e(t('common.edit')) ?></a>
                        &middot;
                        <form method="post" action="/org/roles/<?= e($role['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="muted"><?= e(t('org.not_editable')) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="card card--form">
    <h2><?= $editingRole !== null ? e(t('org.edit_role')) . ': ' . e($editingRole['name']) : e(t('org.create_custom_role')) ?></h2>

    <form method="post" action="<?= e($formAction) ?>">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('org.role_name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($nameValue) ?>" required>
        <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>

        <div class="table-responsive"><table class="table table--compact">
            <thead><tr><th><?= e(t('org.permission')) ?></th><th><?= e(t('org.grant')) ?></th><th><?= e(t('org.scope')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($allPermissions as $key): ?>
                <?php $currentScope = $editingPermissions[$key] ?? null; ?>
                <tr>
                    <td><code><?= e($key) ?></code></td>
                    <td>
                        <input type="checkbox" class="perm-toggle" data-key="<?= e($key) ?>" <?= $currentScope !== null ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <select name="permissions[<?= e($key) ?>]" class="perm-scope" data-key="<?= e($key) ?>" <?= $currentScope === null ? 'disabled' : '' ?>>
                            <?php foreach ($scopes as $scope): ?>
                                <option value="<?= e($scope) ?>" <?= $currentScope === $scope ? 'selected' : '' ?>><?= e(t('org.scope.' . $scope)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <button type="submit" class="btn btn--primary"><?= $editingRole !== null ? e(t('org.save_role')) : e(t('org.create_role')) ?></button>
        <?php if ($editingRole !== null): ?>
            <a href="/org/roles" class="btn"><?= e(t('org.cancel')) ?></a>
        <?php endif; ?>
    </form>
</section>

<script>
// A disabled <select> is not submitted with the form, so an unchecked
// permission simply never appears in permissions[] — no server-side
// "ignore this one" logic needed beyond that.
document.querySelectorAll('.perm-toggle').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        var select = document.querySelector('.perm-scope[data-key="' + this.dataset.key + '"]');
        select.disabled = !this.checked;
    });
});
</script>
