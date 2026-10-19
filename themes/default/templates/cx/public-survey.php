<?php
/**
 * Public survey page (/p/{token}), rendered in public_layout. Mobile
 * first: the score is a row of large tap targets that wraps on narrow
 * screens; works without JavaScript. Shows no ids and no order or
 * contact data.
 *
 * @var array $survey
 * @var array[] $questions active questions (score | rating | text)
 * @var string $token
 * @var array<string, string> $errors
 * @var array $old
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card card--form">
    <h1><?= e(t('cx.public.title')) ?></h1>
    <?php if (!empty($survey['intro_text'])): ?>
        <p><?= e($survey['intro_text']) ?></p>
    <?php endif; ?>

    <form method="post" action="/p/<?= e($token) ?>">
        <?= Csrf::field() ?>
        <?php foreach ($questions as $q): ?>
            <?php $name = 'q' . (int) $q['id']; ?>
            <?php if ($q['kind'] === 'text'): ?>
                <label for="<?= e($name) ?>"><?= e($q['label'] !== '' ? $q['label'] : t('cx.public.comment_default')) ?></label>
                <textarea id="<?= e($name) ?>" name="<?= e($name) ?>" rows="4" maxlength="2000"><?= e((string) ($old[$name] ?? '')) ?></textarea>
            <?php else: ?>
                <fieldset class="score-scale">
                    <legend><?= e($q['label']) ?></legend>
                    <div class="score-scale__options">
                        <?php for ($v = (int) $q['scale_min']; $v <= (int) $q['scale_max']; $v++): ?>
                            <label class="score-scale__option">
                                <input type="radio" name="<?= e($name) ?>" value="<?= e((string) $v) ?>"<?= (string) ($old[$name] ?? '') === (string) $v ? ' checked' : '' ?><?= (int) $q['required'] === 1 ? ' required' : '' ?>>
                                <span><?= e((string) $v) ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                    <div class="score-scale__ends">
                        <span><?= e(t('cx.public.scale_low.' . $survey['type'])) ?></span>
                        <span><?= e(t('cx.public.scale_high.' . $survey['type'])) ?></span>
                    </div>
                </fieldset>
                <?php if (isset($errors[$name])): ?><div class="field-error"><?= e(t($errors[$name])) ?></div><?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('cx.public.submit')) ?></button>
    </form>
</section>
