<?php
/**
 * Appointment settings: bookable resources and appointment types.
 * View-model data from AppointmentSettingsController::index().
 *
 * @var array $resources
 * @var array $types
 * @var array<int, string> $users
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('appointments.settings')) ?></h1>
        <p><?= e(t('appointments.settings_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="/appointments" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('appointments.back_to_calendar')) ?></a>
    </div>
</div>

<section class="card">
    <div class="page-head">
        <div>
            <h2><?= e(t('appointments.resources')) ?></h2>
            <p class="muted"><?= e(t('appointments.resources_hint')) ?></p>
        </div>
        <a href="/appointments/settings/resources/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('appointments.new_resource')) ?></a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('appointments.name')) ?></th>
                    <th><?= e(t('appointments.kind')) ?></th>
                    <th><?= e(t('appointments.linked_user')) ?></th>
                    <th class="num"><?= e(t('appointments.capacity')) ?></th>
                    <th><?= e(t('appointments.status')) ?></th>
                    <th><span class="visually-hidden"><?= e(t('appointments.actions')) ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($resources as $r): ?>
                <tr>
                    <td><a href="/appointments/settings/resources/<?= e($r['id']) ?>/edit"><?= e($r['name']) ?></a></td>
                    <td><?= e(t('appointments.kind.' . $r['kind'])) ?></td>
                    <td><?= e($r['user_id'] !== null ? ($users[$r['user_id']] ?? '—') : '—') ?></td>
                    <td class="num"><?= e($r['capacity']) ?></td>
                    <td><?= $r['is_active'] ? badge(t('appointments.active'), 'active') : badge(t('appointments.inactive'), 'disabled') ?></td>
                    <td class="nowrap">
                        <a href="/appointments/settings/resources/<?= e($r['id']) ?>/edit" class="btn btn--sm btn--ghost"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                        <form method="post" action="/appointments/settings/resources/<?= e($r['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('appointments.delete_resource_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($resources === []): ?>
                <tr><td colspan="6" class="muted"><?= e(t('appointments.resources_empty')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="page-head">
        <div>
            <h2><?= e(t('appointments.types')) ?></h2>
            <p class="muted"><?= e(t('appointments.types_hint')) ?></p>
        </div>
        <a href="/appointments/settings/types/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('appointments.new_type')) ?></a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('appointments.name')) ?></th>
                    <th class="num"><?= e(t('appointments.duration')) ?></th>
                    <th class="num"><?= e(t('appointments.buffer')) ?></th>
                    <th><?= e(t('appointments.status')) ?></th>
                    <th><span class="visually-hidden"><?= e(t('appointments.actions')) ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($types as $type): ?>
                <tr>
                    <td><a href="/appointments/settings/types/<?= e($type['id']) ?>/edit"><?= e($type['name']) ?></a></td>
                    <td class="num"><?= e(t('appointments.minutes_n', ['n' => $type['duration_minutes']])) ?></td>
                    <td class="num"><?= e(t('appointments.minutes_n', ['n' => $type['buffer_minutes']])) ?></td>
                    <td><?= $type['is_active'] ? badge(t('appointments.active'), 'active') : badge(t('appointments.inactive'), 'disabled') ?></td>
                    <td class="nowrap">
                        <a href="/appointments/settings/types/<?= e($type['id']) ?>/edit" class="btn btn--sm btn--ghost"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                        <form method="post" action="/appointments/settings/types/<?= e($type['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('appointments.delete_type_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($types === []): ?>
                <tr><td colspan="5" class="muted"><?= e(t('appointments.types_empty')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
