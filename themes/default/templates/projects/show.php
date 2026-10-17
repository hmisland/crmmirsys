<?php
/** @var array $project */
/** @var array<string, bool> $actions ProjectService::allowedActions() */
/** @var string $owner manager's name */
/** @var array|null $contact */
/** @var array|null $company */
/** @var array $members */
/** @var array $candidates users who can be added (only when the user may manage members) */
/** @var array $links ProjectService::links() — url null when the other module/record isn't viewable */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var array $attachments */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

include __DIR__ . '/_helpers.php';

$id = (int) $project['id'];
$base = '/projects/' . $id;
$status = (string) $project['status'];
$links = $links ?? [];
$facts = array_filter([
    ['user', t('projects.manager'), $owner],
    ['calendar', t('projects.start_date'), $project['start_date'] ? $fmt->date($project['start_date']) : null],
    ['calendar', t('projects.expected_end_date'), $project['expected_end_date'] ? $fmt->date($project['expected_end_date']) : null],
    ['check-circle', t('projects.actual_end_date'), $project['actual_end_date'] ? $fmt->date($project['actual_end_date']) : null],
], fn ($f) => $f[2] !== null && $f[2] !== '');
$lifecycle = array_filter(array_keys($projectActionIcons), fn (string $a) => !empty($actions[$a]));
?>
<section class="card">
    <h1><?= e($project['name']) ?></h1>
    <p class="muted">
        <span class="ltr"><?= e($project['code']) ?></span> ·
        <?= badge(t('projects.status.' . $status), $projectTone($status)) ?>
    </p>

    <?php if ($contact !== null || $company !== null): ?>
        <p>
            <?= e(t('projects.party')) ?>:
            <?php if ($contact !== null): ?><a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a><?php endif; ?>
            <?php if ($contact !== null && $company !== null): ?> · <?php endif; ?>
            <?php if ($company !== null): ?><a href="/companies/<?= e($company['id']) ?>"><?= e($company['name']) ?></a><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($facts !== []): ?>
        <dl class="fact-row">
            <?php foreach ($facts as [$factIcon, $factLabel, $factValue]): ?>
                <div class="fact">
                    <dt><?= icon($factIcon) ?><?= e($factLabel) ?></dt>
                    <dd><?= e((string) $factValue) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php if (!empty($project['description'])): ?>
        <p class="prose-text"><?= nl2br(e($project['description'])) ?></p>
    <?php endif; ?>

    <div class="actions">
        <?php if ($actions['edit']): ?>
            <a href="<?= e($base) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
        <a href="/projects" class="btn btn--ghost"><?= icon('list') ?><?= e(t('projects.back_to_list')) ?></a>
        <?php if ($actions['delete']): ?>
            <form method="post" action="<?= e($base) ?>/delete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($lifecycle !== []): ?>
<section class="card">
    <h2><?= e(t('projects.lifecycle')) ?></h2>
    <div class="actions">
        <?php foreach ($lifecycle as $lifecycleAction): ?>
            <?php if ($lifecycleAction === 'complete') { continue; } ?>
            <form method="post" action="<?= e($base) ?>/status" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= e($lifecycleAction) ?>">
                <?php if ($lifecycleAction === 'cancel'): ?>
                    <button type="submit" class="btn btn--danger" data-confirm><?= icon($projectActionIcons[$lifecycleAction]) ?><?= e(t('projects.action.' . $lifecycleAction)) ?></button>
                <?php else: ?>
                    <button type="submit" class="btn"><?= icon($projectActionIcons[$lifecycleAction]) ?><?= e(t('projects.action.' . $lifecycleAction)) ?></button>
                <?php endif; ?>
            </form>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($actions['complete'])): ?>
        <form method="post" action="<?= e($base) ?>/status" class="inline-form-row">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="complete">
            <label for="actual_end_date"><?= e(t('projects.actual_end_date')) ?></label>
            <input type="text" class="crm-datepicker" id="actual_end_date" name="actual_end_date" value="<?= e(local_today()) ?>">
            <button type="submit" class="btn btn--primary"><?= icon('check-circle') ?><?= e(t('projects.action.complete')) ?></button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= e(t('projects.members')) ?></h2>
    <ul class="item-list">
        <li><?= icon('user') ?><?= e($owner) ?> <span class="muted">(<?= e(t('projects.manager')) ?>)</span></li>
        <?php foreach ($members as $member): ?>
            <li>
                <?= icon('user') ?><?= e(trim($member['first_name'] . ' ' . ($member['last_name'] ?? ''))) ?>
                <?php if ($actions['manage_members']): ?>
                    <form method="post" action="<?= e($base) ?>/members/<?= e($member['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('x') ?><?= e(t('projects.remove_member')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($actions['manage_members'] && $candidates !== []): ?>
        <form method="post" action="<?= e($base) ?>/members" class="inline-form-row">
            <?= Csrf::field() ?>
            <select name="user_id" aria-label="<?= e(t('projects.add_member')) ?>">
                <?php foreach ($candidates as $candidate): ?>
                    <option value="<?= e($candidate['id']) ?>"><?= e(trim($candidate['first_name'] . ' ' . ($candidate['last_name'] ?? ''))) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn"><?= icon('user-plus') ?><?= e(t('projects.add_member')) ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($links !== []): ?>
<section class="card">
    <h2><?= e(t('projects.linked_records')) ?></h2>
    <ul class="item-list">
        <?php foreach ($links as $link): ?>
            <li>
                <span class="muted"><?= e($link['label_key'] !== null ? t($link['label_key']) : $link['type']) ?>:</span>
                <?php if ($link['url'] !== null): ?>
                    <a href="<?= e($link['url']) ?>"><?= e($link['label']) ?></a>
                <?php else: ?>
                    <span><?= e($link['label']) ?></span>
                <?php endif; ?>
                <?php if ($actions['link'] && ($link['can_unlink'] ?? true)): ?>
                    <form method="post" action="<?= e($base) ?>/links/<?= e($link['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('x') ?><?= e(t('projects.unlink')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php
$panelSubjectType = 'project';
$panelSubject = $project;
include $theme->templatesPath() . '/partials/related-panels.php';

$type = 'project';
include $theme->templatesPath() . '/crm/_record_support.php';
?>
