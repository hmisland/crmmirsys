<?php
/**
 * Shown after a survey answer was saved.
 *
 * @var array $survey
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card card--form">
    <h1><?= icon('check-circle') ?><?= e(t('cx.public.thanks_title')) ?></h1>
    <p><?= e(!empty($survey['thank_you_text']) ? $survey['thank_you_text'] : t('cx.public.thanks_text')) ?></p>
</section>
