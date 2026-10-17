<?php
/** @var array|null $segment */
/** @var array $old */
/** @var string $type static|dynamic */
/** @var array<string, string[]> $errors */
/** @var array|null $preview total, rows, rows_visible, errors (form preview of unsaved rules) */
/** @var array|null $builder fields, rules, i18n (dynamic only) */
/** @var bool $canSeeContacts */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

$isNew = $segment === null;
$action = $isNew ? '/marketing/segments' : '/marketing/segments/' . (int) $segment['id'];
$err = fn (string $field): array => array_map(fn ($k) => t($k), $errors[$field] ?? []);
$active = 'segments';
?>
<?php if ($builder !== null): ?>
<script type="application/json" id="segment-builder-data"><?= json_encode($builder, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1><?= e($isNew ? t('marketing.segment.new_' . $type) : t('marketing.segment.edit')) ?><?= help_link($type === 'static' ? 'segments-static' : 'segments-dynamic-builder') ?></h1>
        <p><?= e(t('marketing.segment.type_hint.' . $type)) ?></p>
    </div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>" data-segment-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" required maxlength="150" value="<?= e((string) ($old['name'] ?? '')) ?>">
        <?php foreach ($err('name') as $message): ?><div class="field-error"><?= e($message) ?></div><?php endforeach; ?>

        <label for="description"><?= e(t('marketing.segment.description')) ?></label>
        <input type="text" id="description" name="description" maxlength="500" value="<?= e((string) ($old['description'] ?? '')) ?>">
        <?php foreach ($err('description') as $message): ?><div class="field-error"><?= e($message) ?></div><?php endforeach; ?>

        <?php if ($type === 'dynamic'): ?>
            <h3 class="form-section-title"><?= e(t('marketing.segment.rules')) ?></h3>
            <input type="hidden" name="rules_json" value="<?= e((string) ($old['rules_json'] ?? '')) ?>" data-segment-rules>
            <div class="segment-builder" data-segment-builder></div>
            <noscript><div class="alert alert--warning"><?= e(t('marketing.segment.needs_js')) ?></div></noscript>
            <?php foreach ($err('rules') as $message): ?><div class="field-error"><?= e($message) ?></div><?php endforeach; ?>
            <?php if (!$canSeeContacts): ?><p class="field-hint"><?= e(t('marketing.segment.personal_hidden')) ?></p><?php endif; ?>
        <?php else: ?>
            <p class="field-hint"><?= e(t('marketing.segment.static_members_hint')) ?></p>
        <?php endif; ?>

        <div class="actions">
            <?php if ($type === 'dynamic'): ?>
                <button type="submit" name="action" value="preview" class="btn"><?= icon('eye') ?><?= e(t('marketing.segment.preview')) ?></button>
            <?php endif; ?>
            <button type="submit" name="action" value="save" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($isNew ? '/marketing/segments' : '/marketing/segments/' . (int) $segment['id']) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>

<?php if ($preview !== null): ?>
<section class="card">
    <h2><?= e(t('marketing.segment.preview')) ?></h2>
    <?php if (($preview['errors'] ?? []) !== []): ?>
        <?php foreach ($preview['errors'] as $key): ?><div class="alert alert--error"><?= e(t($key)) ?></div><?php endforeach; ?>
    <?php else: ?>
        <?php include __DIR__ . '/_preview.php'; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
