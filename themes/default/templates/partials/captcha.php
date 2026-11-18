<?php
/**
 * Self-hosted captcha widget for the guest forms (login, register).
 * Include INSIDE the <form>. Optional: $captchaError (string|null) shows
 * a field error under the input (the login page shows it in its alert).
 */

use App\Infrastructure\Security\Captcha;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

if (!Captcha::isEnabled()) {
    return;
}

$captchaError = $captchaError ?? null;
?>
<label for="captcha"><?= e(t('auth.captcha.label')) ?></label>
<div class="captcha">
    <img
        class="captcha__img"
        id="captcha-img"
        src="/captcha?<?= time() ?>"
        width="<?= Captcha::WIDTH ?>"
        height="<?= Captcha::HEIGHT ?>"
        alt="<?= e(t('auth.captcha.label')) ?>"
    >
    <button
        type="button"
        class="btn btn--sm captcha__refresh"
        title="<?= e(t('auth.captcha.refresh')) ?>"
        aria-label="<?= e(t('auth.captcha.refresh')) ?>"
        onclick="var i=document.getElementById('captcha-img'),f=document.getElementById('captcha');i.src='/captcha?'+Date.now();f.value='';f.focus();"
    ><?= icon('refresh') ?></button>
</div>
<input type="text" id="captcha" name="captcha" inputmode="numeric" autocomplete="off" maxlength="8" required>
<?php if ($captchaError): ?><div class="field-error"><?= e($captchaError) ?></div><?php endif; ?>
