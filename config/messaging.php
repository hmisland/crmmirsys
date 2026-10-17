<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

/**
 * Messaging limits — the one place they are set (overridable in .env).
 *
 * test_sends: real test messages (test SMS on Organization settings → SMS,
 * test email on → Messaging providers, and a campaign's "Send a test").
 * All three share the same counters, per user and per organization; in
 * platform SMS mode every test SMS is billed, so this caps that cost too.
 *
 * marketing.sync_audience_limit: audiences up to this many contacts are
 * expanded during the approval request; larger ones are expanded in the
 * background by the queue worker, in chunks.
 * marketing.transactional_max_recipients: a "transactional" campaign may
 * target at most this many selected contacts.
 */
return [
    'test_sends' => [
        'per_user_max' => (int) env('MESSAGING_TEST_SENDS_PER_USER', 5),
        'per_user_window_seconds' => (int) env('MESSAGING_TEST_SENDS_PER_USER_WINDOW', 600),
        'per_organization_max' => (int) env('MESSAGING_TEST_SENDS_PER_ORG', 20),
        'per_organization_window_seconds' => (int) env('MESSAGING_TEST_SENDS_PER_ORG_WINDOW', 3600),
    ],
    // Bale via Safir (modules/MarketingBale). These are OUR OWN conservative safety settings:
    // Safir documents no numeric rate limit, so none of these is a provider limit.
    'bale' => [
        'base_url' => (string) env('BALE_SAFIR_BASE_URL', 'https://safir.bale.ai/api/v3'),
        'requests_per_second' => (float) env('BALE_REQUESTS_PER_SECOND', 5),
        'per_run' => (int) env('BALE_MESSAGES_PER_RUN', 200),
        'connect_timeout_seconds' => (int) env('BALE_CONNECT_TIMEOUT', 5),
        'timeout_seconds' => (int) env('BALE_TIMEOUT', 15),
        // Organization-wide wait after "payment required" / "contact limit" (nothing is marked failed).
        'pause_seconds' => (int) env('BALE_PAUSE_SECONDS', 600),
        // Wait after a Safir rate-limit answer (code 3 / HTTP 429); the batch stops.
        'rate_limit_backoff_seconds' => (int) env('BALE_RATE_LIMIT_BACKOFF', 60),
        // Guard against accidental repeated approvals.
        'approvals_per_user_per_hour' => (int) env('BALE_APPROVALS_PER_USER_PER_HOUR', 20),
    ],
    'marketing' => [
        'sync_audience_limit' => (int) env('MARKETING_SYNC_AUDIENCE_LIMIT', 2000),
        'transactional_max_recipients' => (int) env('MARKETING_TRANSACTIONAL_MAX_RECIPIENTS', 50),
    ],
];
