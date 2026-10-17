<?php
/** @var array|null $template */
/** @var array $old */
/** @var array<string, string[]> $errors */

use App\Infrastructure\Security\Csrf;
use Modules\Marketing\Services\Personalizer;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$isNew = $template === null;
$channel = (string) ($template['channel'] ?? $old['channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
$err = fn (string $field): ?string => isset($errors[$field][0]) ? t($errors[$field][0]) : null;
$val = fn (string $field): string => (string) ($old[$field] ?? '');
$active = 'templates';
?>
<div class="page-head">
    <div><h1><?= e($isNew ? t('marketing.new_template') : t('marketing.edit_template')) ?> — <?= e(t('marketing.channel.' . $channel)) ?></h1></div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<section class="card card--form">
    <form method="post" action="<?= e($isNew ? '/marketing/templates' : '/marketing/templates/' . (int) $template['id']) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="channel" value="<?= e($channel) ?>">

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" required maxlength="150" value="<?= e($val('name')) ?>">
        <?php if ($err('name')): ?><div class="field-error"><?= e($err('name')) ?></div><?php endif; ?>

        <?php if ($channel === 'email'): ?>
            <label for="subject"><?= e(t('marketing.subject')) ?></label>
            <input type="text" id="subject" name="subject" maxlength="255" value="<?= e($val('subject')) ?>">

            <label for="body_html"><?= e(t('marketing.body_html')) ?></label>
            <textarea id="body_html" name="body_html" rows="12" dir="auto"><?= e($val('body_html')) ?></textarea>
            <?php if ($err('body_html')): ?><div class="field-error"><?= e($err('body_html')) ?></div><?php endif; ?>

            <label for="body_text"><?= e(t('marketing.body_text_optional')) ?></label>
            <textarea id="body_text" name="body_text" rows="5" dir="auto"><?= e($val('body_text')) ?></textarea>
        <?php else: ?>
            <label for="body_text"><?= e(t('marketing.sms_text')) ?></label>
            <textarea id="body_text" name="body_text" rows="5" dir="auto" maxlength="900"><?= e($val('body_text')) ?></textarea>
        <?php endif; ?>
        <?php if ($err('body_text')): ?><div class="field-error"><?= e($err('body_text')) ?></div><?php endif; ?>
        <div class="field-hint"><?= e(t('marketing.variables_hint')) ?> <span class="ltr"><?= e(implode('  ', array_map(fn ($v) => '{{' . $v . '}}', array_filter(Personalizer::VARIABLES, fn ($v) => $channel === 'email' || $v !== 'unsubscribe_url')))) ?></span></div>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="/marketing/templates" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
