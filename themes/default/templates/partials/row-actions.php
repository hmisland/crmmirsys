<?php
/**
 * Edit + delete buttons for one list row. A button the user may not use
 * on this record (e.g. someone else's record under an "own" scope) is
 * shown disabled with a tooltip instead of being hidden.
 *
 * @var array{edit: bool, delete: bool} $actions
 * @var string $editUrl
 * @var string $deleteUrl
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

// Why a button is disabled: $actions['reason'] (e.g. an issued invoice),
// otherwise "only the owner or an admin".
$disabledReason = (string) ($actions['reason'] ?? t('bulk.not_yours'));
?>
<?php if ($actions['edit']): ?>
    <a href="<?= e($editUrl) ?>" class="btn btn--sm"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
<?php else: ?>
    <span data-tooltip="<?= e($disabledReason) ?>"><button type="button" class="btn btn--sm" disabled><?= icon('pencil') ?><?= e(t('common.edit')) ?></button></span>
<?php endif; ?>
<?php if ($actions['delete']): ?>
    <form method="post" action="<?= e($deleteUrl) ?>" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn--sm btn--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
    </form>
<?php else: ?>
    <span data-tooltip="<?= e($disabledReason) ?>"><button type="button" class="btn btn--sm btn--danger" disabled><?= icon('trash') ?><?= e(t('common.delete')) ?></button></span>
<?php endif; ?>
