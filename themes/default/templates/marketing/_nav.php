<?php
/** @var string $active one of dashboard|campaigns|segments|templates|history|suppressions */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$marketingTabs = [
    'dashboard' => ['/marketing', 'dashboard', 'marketing.nav.dashboard'],
    'campaigns' => ['/marketing/campaigns', 'megaphone', 'marketing.nav.campaigns'],
    'segments' => ['/marketing/segments', 'filter', 'marketing.nav.segments'],
    'templates' => ['/marketing/templates', 'file-text', 'marketing.nav.templates'],
    'history' => ['/marketing/history', 'history', 'marketing.nav.history'],
    'suppressions' => ['/marketing/suppressions', 'ban', 'marketing.nav.suppressions'],
];
?>
<nav class="card card--tabs" aria-label="<?= e(t('marketing.title')) ?>">
    <div class="tabs">
        <?php foreach ($marketingTabs as $key => [$href, $tabIcon, $label]): ?>
            <a class="tab-btn" href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= icon($tabIcon) ?><?= e(t($label)) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
