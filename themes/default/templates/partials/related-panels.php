<?php
/**
 * Slot for panels other modules contribute to a record page
 * (App\Infrastructure\Records\RelatedPanelRegistry). The host page
 * names only its own subject type — never the contributing module.
 * Renders nothing when no panel is registered or visible.
 *
 * @var \App\Infrastructure\Records\RelatedPanelRegistry|null $relatedPanels (injected by TemplateEngine)
 * @var string $panelSubjectType e.g. "contact", "deal"
 * @var array  $panelSubject     the record the page shows
 */

use App\Infrastructure\Security\CurrentUser;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

foreach (($relatedPanels ?? null)?->render($panelSubjectType, $panelSubject, CurrentUser::user()) ?? [] as $rendered): ?>
<section class="card" data-related-panel="<?= e($rendered['panel']->id) ?>">
    <h2><?php if ($rendered['panel']->icon !== null): ?><?= icon($rendered['panel']->icon) ?><?php endif; ?><?= e(t($rendered['panel']->titleKey)) ?></h2>
    <?= $rendered['html'] ?>
</section>
<?php endforeach; ?>
