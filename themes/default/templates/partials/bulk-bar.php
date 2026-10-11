<?php
/**
 * Bulk-action bar above a list table. Row checkboxes live in the table
 * (name="ids[]" form="bulk-form" data-bulk-item) and join this form via
 * the HTML form attribute, so each row can keep its own delete form.
 * The header checkbox (data-bulk-all) selects every enabled row on the
 * current page. app.js keeps the counter and the button state in sync.
 *
 * @var string $bulkAction POST url, e.g. /contacts/bulk-delete
 * @var string|null $bulkConfirm optional confirm text (defaults to bulk.confirm_delete)
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$returnTo = (string) ($_SERVER['REQUEST_URI'] ?? '');
$bulkConfirm = $bulkConfirm ?? t('bulk.confirm_delete');
?>
<form method="post" action="<?= e($bulkAction) ?>" id="bulk-form" class="bulk-bar" data-bulk-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <span class="bulk-bar__count muted" data-bulk-count data-template="<?= e(t('bulk.selected', ['count' => '{n}'])) ?>"><?= e(t('bulk.selected', ['count' => 0])) ?></span>
    <button type="submit" class="btn btn--sm btn--danger" data-bulk-submit disabled data-confirm="<?= e($bulkConfirm) ?>"><?= icon('trash') ?><?= e(t('bulk.delete_selected')) ?></button>
</form>
