<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

/**
 * Integration limits (docs/integrations-architecture.md), overridable in .env.
 *
 * woocommerce.sync_interval_minutes: scheduled incremental sync per store
 *   (also recovers webhooks that never arrived). Minimum 5.
 * woocommerce.per_page / pages_per_job: one sync job reads at most
 *   per_page × pages_per_job orders, then queues a continuation — small
 *   batches for shared hosting.
 * woocommerce.initial_days: the first sync of a new store only looks this
 *   many days back (the whole shop history is never imported).
 * woocommerce.timeout_seconds: per API request.
 */
return [
    'woocommerce' => [
        'sync_interval_minutes' => max(5, (int) env('WOOCOMMERCE_SYNC_INTERVAL_MINUTES', 15)),
        'per_page' => max(10, min(100, (int) env('WOOCOMMERCE_PER_PAGE', 50))),
        'pages_per_job' => max(1, min(10, (int) env('WOOCOMMERCE_PAGES_PER_JOB', 2))),
        'initial_days' => max(1, min(365, (int) env('WOOCOMMERCE_INITIAL_DAYS', 30))),
        'timeout_seconds' => max(5, min(60, (int) env('WOOCOMMERCE_TIMEOUT_SECONDS', 20))),
    ],
];
