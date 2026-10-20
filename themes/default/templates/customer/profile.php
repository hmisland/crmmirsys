<?php
/**
 * Customer Portal → my profile: READ-ONLY (V1). No form, no edit action.
 * Only customer-facing fields; internal ones (owner, team, source, notes,
 * category, status…) are never selected.
 *
 * @var array $profile
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$address = implode(', ', array_filter([$profile['province'] ?? null, $profile['city'] ?? null, $profile['address'] ?? null], fn ($v) => trim((string) $v) !== ''));
$emails = array_values(array_unique(array_filter(array_merge([(string) ($profile['email'] ?? '')], $profile['emails']))));
$phones = [];
foreach ($profile['phones'] as $p) {
    $phones[] = $p['number'] . ' (' . t('customer_portal.profile.phone_' . ($p['type'] === 'landline' ? 'landline' : 'mobile')) . ')';
}
if ($phones === [] && trim((string) ($profile['phone'] ?? '')) !== '') {
    $phones[] = (string) $profile['phone'];
}
$rows = [
    'first_name' => $profile['first_name'] ?? '',
    'last_name' => $profile['last_name'] ?? '',
    'company' => $profile['company_name'] ?? '',
    'email' => implode(' · ', $emails),
    'phone' => implode(' · ', $phones),
    'national_id' => $profile['national_id'] ?? '',
    'economic_code' => $profile['economic_code'] ?? '',
    'address' => $address,
    'postal_code' => $profile['postal_code'] ?? '',
    'country' => $profile['country'] ?? '',
];
?>
<section class="card">
    <h1><?= e(t('customer_portal.nav.profile')) ?></h1>
    <p class="field-hint"><?= e(t('customer_portal.profile.read_only')) ?></p>
    <dl class="customer-facts">
        <?php foreach ($rows as $key => $value): ?>
            <dt><?= e(t('customer_portal.profile.' . $key)) ?></dt>
            <dd><?= trim((string) $value) !== '' ? e($value) : '<span class="muted">—</span>' ?></dd>
        <?php endforeach; ?>
    </dl>
</section>
