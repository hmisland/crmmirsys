<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Infrastructure\Repositories\BaseRepository;

/**
 * integration_jobs — the MySQL-backed queue (core migration 0085).
 *
 * Idempotency: enqueue() is INSERT IGNORE on the unique dedupe_key.
 *  - webhook jobs keep their key ("wh:{connection}:{hash}") after they
 *    finish, so the same delivery replayed later is still a no-op (until
 *    purgeFinished() removes old rows);
 *  - sync jobs ("sync:{connection}:{resource}") release their key when
 *    they finish, so at most ONE pending/running sync per connection and
 *    resource exists — a second "sync now" click or an overlapping cron
 *    run never doubles the API calls.
 *
 * Claiming is atomic (UPDATE … LIMIT n with a random lock token), so two
 * overlapping cron runs never process the same job.
 */
final class IntegrationJobRepository extends BaseRepository
{
    /** Back-off per attempt (seconds): 1 min, 5 min, 15 min, 1 h, 3 h. */
    private const BACKOFF = [60, 300, 900, 3600, 10800];

    public function enqueue(int $organizationId, int $connectionId, string $type, string $resource, ?string $externalId, ?string $payload, ?string $dedupeKey, int $delaySeconds = 0, int $maxAttempts = 5): bool
    {
        $stmt = $this->query(
            'INSERT IGNORE INTO integration_jobs
                (organization_id, connection_id, job_type, resource, external_id, payload, dedupe_key, status, attempts, max_attempts, available_at)
             VALUES (:org, :conn, :type, :resource, :external_id, :payload, :dedupe, \'pending\', 0, :max, :available)',
            [
                'org' => $organizationId,
                'conn' => $connectionId,
                'type' => $type,
                'resource' => $resource,
                'external_id' => $externalId,
                'payload' => $payload,
                'dedupe' => $dedupeKey,
                'max' => $maxAttempts,
                'available' => date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
            ]
        );

        return $stmt->rowCount() === 1;
    }

    public static function syncKey(int $connectionId, string $resource): string
    {
        return 'sync:' . $connectionId . ':' . $resource;
    }

    /** @return IntegrationJob[] */
    public function claim(int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $this->query(
            "UPDATE integration_jobs SET status = 'running', locked_at = :now, locked_by = :token, attempts = attempts + 1
             WHERE status = 'pending' AND available_at <= :now2
             ORDER BY available_at, id LIMIT " . max(1, $limit),
            ['now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'), 'token' => $token]
        );

        return array_map(
            fn (array $row) => IntegrationJob::fromRow($row),
            $this->findMany('SELECT * FROM integration_jobs WHERE locked_by = :token ORDER BY id', ['token' => $token])
        );
    }

    public function complete(IntegrationJob $job): void
    {
        $this->finish($job, 'done', null);
    }

    /** Permanent failure, or the job's attempts are used up. */
    public function fail(IntegrationJob $job, string $errorKey): void
    {
        $this->finish($job, 'failed', $errorKey);
    }

    /** Temporary failure: back to pending with back-off, or failed once max_attempts is reached. */
    public function retryLater(IntegrationJob $job, string $errorKey): void
    {
        $row = $this->findOne('SELECT attempts, max_attempts FROM integration_jobs WHERE id = :id', ['id' => $job->id]);
        if ($row === null || (int) $row['attempts'] >= (int) $row['max_attempts']) {
            $this->fail($job, $errorKey);

            return;
        }
        $delay = self::BACKOFF[min(count(self::BACKOFF) - 1, max(0, (int) $row['attempts'] - 1))];
        $this->query(
            "UPDATE integration_jobs SET status = 'pending', locked_at = NULL, locked_by = NULL, last_error = :err, available_at = :at WHERE id = :id",
            ['err' => mb_substr($errorKey, 0, 255), 'at' => date('Y-m-d H:i:s', time() + $delay), 'id' => $job->id]
        );
    }

    /** Jobs left "running" by a crashed run go back to the queue (their attempt still counts). */
    public function recoverStale(int $olderThanSeconds = 900): int
    {
        return $this->query(
            "UPDATE integration_jobs SET status = 'pending', locked_at = NULL, locked_by = NULL WHERE status = 'running' AND locked_at < :before",
            ['before' => date('Y-m-d H:i:s', time() - $olderThanSeconds)]
        )->rowCount();
    }

    /** Finished jobs older than $days (keeps the table small on shared hosting). */
    public function purgeFinished(int $days = 14): int
    {
        return $this->query(
            "DELETE FROM integration_jobs WHERE status IN ('done', 'failed', 'cancelled') AND finished_at < :before LIMIT 2000",
            ['before' => date('Y-m-d H:i:s', time() - $days * 86400)]
        )->rowCount();
    }

    /** @return array{pending: int, running: int, failed: int} for one connection (settings page) */
    public function countsForConnection(int $connectionId, int $organizationId): array
    {
        $rows = $this->findMany(
            "SELECT status, COUNT(*) AS c FROM integration_jobs WHERE connection_id = :conn AND organization_id = :org AND status IN ('pending', 'running', 'failed') GROUP BY status",
            ['conn' => $connectionId, 'org' => $organizationId]
        );
        $counts = ['pending' => 0, 'running' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    public function cancel(IntegrationJob $job, string $reasonKey): void
    {
        $this->finish($job, 'cancelled', $reasonKey);
    }

    private function finish(IntegrationJob $job, string $status, ?string $errorKey): void
    {
        $releaseKey = $job->type === IntegrationJob::SYNC ? ', dedupe_key = NULL' : '';
        $this->query(
            "UPDATE integration_jobs SET status = :status, last_error = :err, finished_at = :now, locked_at = NULL, locked_by = NULL{$releaseKey} WHERE id = :id",
            ['status' => $status, 'err' => $errorKey !== null ? mb_substr($errorKey, 0, 255) : null, 'now' => date('Y-m-d H:i:s'), 'id' => $job->id]
        );
    }
}
