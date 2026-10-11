<?php
/**
 * One appointment as a compact link card, used by the day grid and the
 * week view. Expects $item (an appointment row from AppointmentRepository)
 * and $fmt (LocaleFormatter) in scope.
 *
 * @var array $item
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;

$who = !empty($item['contact_id']) ? trim($item['contact_first_name'] . ' ' . ($item['contact_last_name'] ?? '')) : t('appointments.internal_meeting');
$what = (string) ($item['title'] ?? '') !== '' ? (string) $item['title'] : (string) ($item['type_name'] ?? '');
$range = substr((string) $item['starts_at'], 11, 5) . '–' . substr((string) $item['ends_at'], 11, 5);
?>
<a class="appt-chip appt-chip--<?= e($item['status']) ?>" href="/appointments/<?= e($item['id']) ?>">
    <span class="appt-chip__time"><span class="ltr"><?= e($fmt->digits($range)) ?></span></span>
    <span class="appt-chip__who"><?= e($who) ?></span>
    <?php if ($what !== ''): ?>
        <span class="appt-chip__what"><?= e($what) ?></span>
    <?php endif; ?>
    <?php if ($item['status'] !== 'scheduled'): ?>
        <span><?= badge(t('appointments.status.' . $item['status']), $item['status']) ?></span>
    <?php endif; ?>
</a>
