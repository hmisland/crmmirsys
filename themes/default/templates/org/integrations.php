<?php
/**
 * Organization settings → Integrations. One card per registered
 * integration provider (WooCommerce first); each lists this
 * organization's connections (e.g. several stores) and a form to add
 * one. Secrets are never printed — only a hint; the webhook URL and
 * secret live on their own page (/org/integrations/{id}/webhook).
 *
 * @var array<string, array{provider: \App\Infrastructure\Integrations\IntegrationProviderInterface, entitled: bool, connections: array[]}> $overview
 * @var int $editId connection whose edit form is open
 * @var array<string, string> $errors field => translation key (after a failed save)
 */

use App\Infrastructure\Integrations\IntegrationField;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$statusTone = fn (string $status): string => match ($status) {
    'connected' => 'active',
    'error' => 'failed',
    'configured' => 'pending',
    default => 'neutral',
};

/** @param IntegrationField[] $fields */
$renderFields = function (string $prefix, array $fields, array $values, ?array $connection) use ($errors): void {
    ?>
    <label for="<?= e($prefix) ?>-name"><?= e(t('integrations.field.name')) ?></label>
    <input type="text" id="<?= e($prefix) ?>-name" name="name" required maxlength="120" value="<?= e($connection['name'] ?? '') ?>">
    <?php if (isset($errors['name'])): ?><div class="field-error"><?= e(t($errors['name'])) ?></div><?php endif; ?>
    <?php foreach ($fields as $field): ?>
        <?php
        $id = $prefix . '-' . $field->name;
        $type = match ($field->type) {
            IntegrationField::SECRET => 'password',
            IntegrationField::URL => 'url',
            IntegrationField::NUMBER => 'number',
            default => 'text',
        };
        $placeholder = $field->isSecret() && !empty($connection['has_credentials'])
            ? t('integrations.secret_saved', ['hint' => (string) ($connection['credentials_hint'] ?? '')])
            : '';
        ?>
        <label for="<?= e($id) ?>"><?= e(t($field->labelKey)) ?></label>
        <input type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($field->name) ?>" maxlength="<?= e($field->maxLength) ?>"
               <?= $field->type !== IntegrationField::TEXT ? 'dir="ltr"' : '' ?> autocomplete="off"
               <?= $field->required && (!$field->isSecret() || $connection === null) ? 'required' : '' ?>
               value="<?= e($field->isSecret() ? '' : (string) ($values[$field->name] ?? '')) ?>"
               placeholder="<?= e($placeholder) ?>">
        <?php if (isset($errors[$field->name])): ?><div class="field-error"><?= e(t($errors[$field->name])) ?></div><?php endif; ?>
        <?php if ($field->hintKey !== null): ?><div class="field-hint"><?= e(t($field->hintKey)) ?></div><?php endif; ?>
    <?php endforeach; ?>
    <?php
};
?>
<div class="page-head">
    <div>
        <h1><?= e(t('integrations.title')) ?></h1>
        <p><?= e(t('integrations.subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/org/settings" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('messaging.back_to_settings')) ?></a>
    </div>
</div>

<?php if ($overview === []): ?>
    <section class="card"><p class="muted"><?= e(t('integrations.none_available')) ?></p></section>
<?php endif; ?>

<div class="provider-grid">
<?php foreach ($overview as $key => $entry): ?>
    <?php $provider = $entry['provider']; $disabled = !$entry['entitled']; ?>
    <section class="card provider-card<?= $disabled ? ' provider-card--unavailable' : '' ?>" id="provider-<?= e($key) ?>">
        <div class="provider-card__head">
            <h2><span class="kpi__icon kpi__icon--info"><?= icon($provider->icon()) ?></span><?= e(t($provider->nameKey())) ?></h2>
            <?= $disabled ? badge(t('messaging.module_not_purchased'), 'disabled') : badge(t('messaging.module_available'), 'active') ?>
        </div>
        <p class="field-hint"><?= e(t($provider->descriptionKey())) ?></p>
        <?php if ($disabled): ?>
            <p class="locked"><?= icon('lock') ?><?= e(t('integrations.module_hint')) ?></p>
        <?php endif; ?>

        <?php foreach ($entry['connections'] as $c): ?>
            <div class="card card--form" id="connection-<?= e($c['id']) ?>">
                <div class="provider-card__head">
                    <h3><?= icon('link') ?><?= e($c['name']) ?></h3>
                    <span>
                        <?= badge(t('integrations.status.' . $c['status']), $statusTone((string) $c['status'])) ?>
                        <?= $c['enabled'] ? badge(t('integrations.enabled'), 'enabled') : badge(t('integrations.disabled'), 'disabled') ?>
                    </span>
                </div>
                <?php if (!empty($c['config']['store_url'])): ?>
                    <p><span class="ltr"><?= e((string) $c['config']['store_url']) ?></span></p>
                <?php endif; ?>
                <dl class="fact-row">
                    <div class="fact">
                        <dt><?= icon('check-circle') ?><?= e(t('integrations.last_success')) ?></dt>
                        <dd><span class="ltr"><?= e(local_time($c['last_successful_sync_at']) ?? '—') ?></span></dd>
                    </div>
                    <div class="fact">
                        <dt><?= icon('clock') ?><?= e(t('integrations.last_attempt')) ?></dt>
                        <dd><span class="ltr"><?= e(local_time($c['last_attempted_sync_at']) ?? '—') ?></span></dd>
                    </div>
                    <div class="fact">
                        <dt><?= icon('zap') ?><?= e(t('integrations.last_webhook')) ?></dt>
                        <dd><span class="ltr"><?= e(local_time($c['last_webhook_at']) ?? '—') ?></span></dd>
                    </div>
                    <div class="fact">
                        <dt><?= icon('list') ?><?= e(t('integrations.queue')) ?></dt>
                        <dd><?= e(t('integrations.queue_counts', ['pending' => (string) ($c['jobs']['pending'] + $c['jobs']['running']), 'failed' => (string) $c['jobs']['failed']])) ?></dd>
                    </div>
                </dl>
                <?php if (!empty($c['last_error'])): ?>
                    <div class="alert alert--error"><?= e(t((string) $c['last_error'])) ?> <span class="ltr"><?= e(local_time($c['last_error_at']) ?? '') ?></span></div>
                <?php endif; ?>

                <div class="actions">
                    <form method="post" action="/org/integrations/<?= e($c['id']) ?>/sync">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--primary"<?= $disabled || !$c['enabled'] ? ' disabled' : '' ?>><?= icon('refresh') ?><?= e(t('integrations.sync_now')) ?></button>
                    </form>
                    <form method="post" action="/org/integrations/<?= e($c['id']) ?>/test">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn"<?= $disabled ? ' disabled' : '' ?>><?= icon('activity') ?><?= e(t('integrations.test')) ?></button>
                    </form>
                    <a href="/org/integrations/<?= e($c['id']) ?>/webhook" class="btn"><?= icon('zap') ?><?= e(t('integrations.webhook_settings')) ?></a>
                    <?php foreach ($c['links'] ?? [] as $link): ?>
                        <a href="<?= e($link['href']) ?>" class="btn"><?= icon($link['icon']) ?><?= e(t($link['label'])) ?></a>
                    <?php endforeach; ?>
                    <a href="/org/integrations?edit=<?= e($c['id']) ?>#connection-<?= e($c['id']) ?>" class="btn"><?= icon('pencil') ?><?= e(t('integrations.edit')) ?></a>
                    <form method="post" action="/org/integrations/<?= e($c['id']) ?>/enabled">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="enabled" value="<?= $c['enabled'] ? '0' : '1' ?>">
                        <button type="submit" class="btn"<?= $disabled ? ' disabled' : '' ?>><?= icon($c['enabled'] ? 'ban' : 'check') ?><?= e(t($c['enabled'] ? 'integrations.disable' : 'integrations.enable')) ?></button>
                    </form>
                    <form method="post" action="/org/integrations/<?= e($c['id']) ?>/delete">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('integrations.delete_confirm')) ?>"<?= $disabled ? ' disabled' : '' ?>><?= icon('trash') ?><?= e(t('integrations.delete')) ?></button>
                    </form>
                </div>

                <?php if ($editId === (int) $c['id']): ?>
                    <form method="post" action="/org/integrations/<?= e($c['id']) ?>">
                        <?= Csrf::field() ?>
                        <fieldset class="form-stack fieldset--plain"<?= $disabled ? ' disabled' : '' ?>>
                            <?php $renderFields('edit-' . $c['id'], $provider->fields(), $c['config'], $c); ?>
                            <div class="field-hint"><?= e(t('integrations.secret_keep_hint')) ?></div>
                            <div class="actions">
                                <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('integrations.save')) ?></button>
                                <a href="/org/integrations#connection-<?= e($c['id']) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
                            </div>
                        </fieldset>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($entry['connections'] === []): ?>
            <p class="muted"><?= e(t('integrations.no_connections')) ?></p>
        <?php endif; ?>

        <details class="card card--form" id="new-<?= e($key) ?>"<?= $errors !== [] && $editId === 0 ? ' open' : '' ?>>
            <summary><?= icon('plus') ?><?= e(t('integrations.add_connection')) ?></summary>
            <form method="post" action="/org/integrations/new/<?= e($key) ?>">
                <?= Csrf::field() ?>
                <fieldset class="form-stack fieldset--plain"<?= $disabled ? ' disabled' : '' ?>>
                    <?php $renderFields('new-' . $key, $provider->fields(), [], null); ?>
                    <div class="actions">
                        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('integrations.create')) ?></button>
                    </div>
                </fieldset>
            </form>
        </details>
    </section>
<?php endforeach; ?>
</div>
