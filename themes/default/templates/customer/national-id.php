<?php
/**
 * Customer Portal sign-in with the national ID: the ID, then the masked
 * destinations of the matching contact (the code still has to be entered).
 *
 * @var string $slug
 * @var array<string, string[]> $errors
 * @var bool $notFound no destination could be offered (one neutral answer)
 * @var list<string> $options masked destinations
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$base = '/customer/login/' . rawurlencode($slug);
?>
<section class="card card--form">
    <h1><?= icon('key') ?><?= e(t('customer_portal.national_id.title')) ?></h1>

    <?php if ($options !== []): ?>
        <form method="post" action="<?= e($base . '/choose') ?>">
            <?= Csrf::field() ?>
            <fieldset class="fieldset--plain">
                <legend><?= e(t('customer_portal.national_id.choose')) ?></legend>
                <div class="customer-choice">
                    <?php foreach ($options as $i => $hint): ?>
                        <label class="check"><input type="radio" name="destination" value="<?= e((string) $i) ?>"<?= $i === 0 ? ' checked' : '' ?> required> <span class="ltr"><?= e($hint) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="actions">
                <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('customer_portal.login.send_code')) ?></button>
            </div>
        </form>
    <?php else: ?>
        <p class="field-hint"><?= e(t('customer_portal.national_id.hint')) ?></p>
        <?php if ($notFound): ?><div class="alert alert--warning"><?= e(t('customer_portal.national_id.not_found')) ?></div><?php endif; ?>
        <form method="post" action="<?= e($base . '/national-id') ?>">
            <?= Csrf::field() ?>
            <label for="national_id"><?= e(t('customer_portal.national_id.field')) ?></label>
            <input type="text" id="national_id" name="national_id" class="ltr" required maxlength="20" inputmode="numeric" autocomplete="off">
            <?php if (isset($errors['national_id'])): ?><div class="field-error"><?= e(t((string) $errors['national_id'][0])) ?></div><?php endif; ?>
            <div class="actions">
                <button type="submit" class="btn btn--primary"><?= icon('search') ?><?= e(t('customer_portal.national_id.continue')) ?></button>
            </div>
        </form>
    <?php endif; ?>
    <p><a href="<?= e($base) ?>"><?= icon('arrow-left') ?><?= e(t('customer_portal.login.back')) ?></a></p>
</section>
