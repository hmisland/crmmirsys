<?php
/**
 * Pagination bar for a list: "showing X–Y of Z", rows-per-page picker
 * and page links. Include it right above (and optionally below) the
 * table, after the filter form.
 *
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use App\Infrastructure\Pagination\Pagination;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

/** Hidden inputs for a (possibly nested) query value, e.g. tags[]=a. */
$hiddenInputs = function (string $name, mixed $value) use (&$hiddenInputs): string {
    if (is_array($value)) {
        $html = '';
        foreach ($value as $k => $v) {
            $html .= $hiddenInputs($name . '[' . $k . ']', $v);
        }

        return $html;
    }

    return '<input type="hidden" name="' . e($name) . '" value="' . e((string) $value) . '">';
};
?>
<nav class="pagination-bar" aria-label="<?= e(t('pagination.label')) ?>">
    <span class="pagination-bar__summary muted">
        <?= e(t('pagination.summary', ['first' => $pagination->from(), 'last' => $pagination->to(), 'count' => $pagination->total])) ?>
    </span>

    <form method="get" class="pagination-bar__size">
        <?php foreach ($pagination->filterQuery() as $name => $value): ?>
            <?= $hiddenInputs((string) $name, $value) ?>
        <?php endforeach; ?>
        <label for="per-page-<?= e(spl_object_id($pagination)) ?>"><?= e(t('pagination.per_page')) ?></label>
        <select id="per-page-<?= e(spl_object_id($pagination)) ?>" name="per_page" data-autosubmit>
            <?php foreach (Pagination::PER_PAGE_OPTIONS as $size): ?>
                <option value="<?= e($size) ?>" <?= $size === $pagination->perPage ? 'selected' : '' ?>><?= e($size) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn--sm"><?= icon('check') ?><?= e(t('pagination.apply')) ?></button></noscript>
    </form>

    <?php if ($pagination->lastPage() > 1): ?>
        <div class="pagination">
            <?php if ($pagination->page > 1): ?>
                <a href="<?= e($pagination->url($pagination->page - 1)) ?>" rel="prev" aria-label="<?= e(t('pagination.previous')) ?>"><?= icon('chevron-left') ?></a>
            <?php endif; ?>
            <?php foreach ($pagination->window() as $p): ?>
                <?php if ($p === null): ?>
                    <span aria-hidden="true">…</span>
                <?php elseif ($p === $pagination->page): ?>
                    <span aria-current="page"><?= e($p) ?></span>
                <?php else: ?>
                    <a href="<?= e($pagination->url($p)) ?>"><?= e($p) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($pagination->page < $pagination->lastPage()): ?>
                <a href="<?= e($pagination->url($pagination->page + 1)) ?>" rel="next" aria-label="<?= e(t('pagination.next')) ?>"><?= icon('chevron-right') ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</nav>
