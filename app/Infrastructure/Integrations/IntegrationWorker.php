<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Cron side of the integration framework (registered as the
 * "integrations" scheduled task, app/bin/scheduler.php):
 *
 *  1. recover jobs left running by a crashed run, purge old finished jobs;
 *  2. schedule: for each enabled, entitled connection whose provider
 *     interval has passed, queue one incremental sync job per resource
 *     (deduplicated — never two pending syncs for the same resource).
 *     This is also what recovers webhooks that never arrived;
 *  3. process queued jobs (webhooks + sync batches) in small claims
 *     until the time budget is used. Each job runs inside its own
 *     organization's context; a disabled connection or a module the
 *     organization no longer holds cancels the job instead of calling out.
 */
final class IntegrationWorker
{
    private const CLAIM_SIZE = 5;
    private const SCHEDULE_SCAN = 50;

    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationConnectionRepository $connections,
        private readonly IntegrationJobRepository $jobs,
        private readonly OrganizationEntitlements $entitlements,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array{scheduled: int, processed: int, failed: int} */
    public function run(int $budgetSeconds): array
    {
        $deadline = time() + max(1, $budgetSeconds);
        $this->entitlements->reset();
        $this->jobs->recoverStale();
        if (random_int(1, 30) === 1) {
            $this->jobs->purgeFinished();
        }

        $stats = ['scheduled' => $this->scheduleDueSyncs(), 'processed' => 0, 'failed' => 0];

        while (time() < $deadline) {
            $batch = $this->jobs->claim(self::CLAIM_SIZE);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $job) {
                $ok = $this->process($job);
                $stats[$ok ? 'processed' : 'failed']++;
            }
        }

        return $stats;
    }

    public function scheduleDueSyncs(): int
    {
        $queued = 0;
        // Shortest possible provider interval is 5 minutes; each provider's own interval is checked below.
        foreach ($this->connections->dueCandidates(date('Y-m-d H:i:s', time() - 300), self::SCHEDULE_SCAN) as $connection) {
            $provider = $this->registry->get($connection->provider());
            if ($provider === null || !$this->entitlements->organizationHas($connection->organizationId(), $provider->moduleKey())) {
                continue;
            }
            $interval = $provider->syncIntervalMinutes($connection);
            $last = $connection->lastAttemptedSyncAt();
            if ($interval <= 0 || ($last !== null && strtotime($last . ' UTC') > time() - $interval * 60)) {
                continue;
            }
            foreach ($provider->syncResources() as $resource) {
                $queued += $this->jobs->enqueue(
                    $connection->organizationId(), $connection->id(), IntegrationJob::SYNC, $resource, null, null,
                    IntegrationJobRepository::syncKey($connection->id(), $resource)
                ) ? 1 : 0;
            }
            // Marks the attempt now so the next minute's run does not reschedule it.
            $this->connections->touch($connection->id(), $connection->organizationId(), ['last_attempted_sync_at' => date('Y-m-d H:i:s')]);
        }

        return $queued;
    }

    private function process(IntegrationJob $job): bool
    {
        $connection = $this->connections->findForJob($job->connectionId, $job->organizationId);
        $provider = $connection !== null ? $this->registry->get($connection->provider()) : null;
        if ($connection === null || $provider === null) {
            $this->jobs->cancel($job, 'integrations.error.connection_missing');

            return false;
        }
        if (!$connection->enabled()) {
            $this->jobs->cancel($job, 'integrations.error.disabled');

            return false;
        }
        if (!$this->entitlements->organizationHas($job->organizationId, $provider->moduleKey())) {
            $this->jobs->cancel($job, 'integrations.error.module_unavailable');

            return false;
        }

        $now = date('Y-m-d H:i:s');
        if ($job->type === IntegrationJob::SYNC) {
            $this->connections->touch($connection->id(), $connection->organizationId(), ['last_attempted_sync_at' => $now]);
        }

        try {
            $outcome = OrganizationContext::runAs($job->organizationId, fn () => $provider->handleJob($connection, $job));
        } catch (\Throwable $e) {
            $this->logger?->error('Integration job crashed', ['job' => $job->id, 'provider' => $connection->provider(), 'exception' => $e->getMessage()]);
            $outcome = JobOutcome::retry('integrations.error.internal');
        }

        if ($outcome->status === JobOutcome::DONE) {
            if ($outcome->syncState !== null) {
                $this->connections->mergeSyncState($connection, $outcome->syncState);
            }
            $this->jobs->complete($job);
            $fields = ['status' => 'connected', 'last_error' => null];
            if ($job->type === IntegrationJob::SYNC && !$outcome->more) {
                $fields['last_successful_sync_at'] = $now;
            }
            $this->connections->touch($connection->id(), $connection->organizationId(), $fields);
            if ($outcome->more) {
                $this->jobs->enqueue(
                    $job->organizationId, $job->connectionId, IntegrationJob::SYNC, $job->resource, null, null,
                    IntegrationJobRepository::syncKey($job->connectionId, $job->resource)
                );
            }

            return true;
        }

        if ($outcome->status === JobOutcome::RETRY) {
            $this->jobs->retryLater($job, (string) $outcome->errorKey);
        } else {
            $this->jobs->fail($job, (string) $outcome->errorKey);
        }
        $this->connections->touch($connection->id(), $connection->organizationId(), [
            'status' => 'error',
            'last_error' => mb_substr((string) $outcome->errorKey, 0, 255),
            'last_error_at' => $now,
        ]);

        return false;
    }
}
