<?php
/**
 * Small display helpers shared by the project pages (presentation only).
 */

/** badge() tone for a project status (badge() already knows planned/active/completed/cancelled). */
$projectTone = fn (string $status): string => match ($status) {
    'on_hold' => 'pending',
    default => $status,
};

/** "Contact · Company", whichever are set. */
$projectParty = fn (array $p): string => implode(' · ', array_filter([
    trim((string) ($p['contact_name'] ?? '')),
    trim((string) ($p['company_name'] ?? '')),
], fn ($v) => $v !== '')) ?: '—';

/** Lifecycle action => icon (ProjectService::TRANSITIONS order). */
$projectActionIcons = [
    'plan' => 'calendar',
    'start' => 'check',
    'hold' => 'clock',
    'resume' => 'refresh',
    'complete' => 'check-circle',
    'reopen' => 'undo',
    'cancel' => 'x-circle',
];
