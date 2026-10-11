<?php
/**
 * Small display helpers shared by the contract pages (presentation only).
 */

use function App\Infrastructure\Theme\t;

/** badge() tone for a contract status (badge() already knows active/expired/cancelled). */
$contractTone = fn (string $status): string => match ($status) {
    'pending_approval' => 'pending',
    'terminated' => 'rejected',
    default => $status,
};

/** "Contact · Company", whichever are set. */
$contractParty = fn (array $c): string => implode(' · ', array_filter([
    trim((string) ($c['contact_name'] ?? '')),
    trim((string) ($c['company_name'] ?? '')),
], fn ($v) => $v !== '')) ?: '—';

/** Amount with locale grouping + currency code, at the currency's display precision (money()). */
$contractValue = function ($fmt, $value, ?string $currencyCode): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return \App\Infrastructure\Theme\money((string) $value, $currencyCode, $fmt) . ($currencyCode ? ' ' . $currencyCode : '');
};
