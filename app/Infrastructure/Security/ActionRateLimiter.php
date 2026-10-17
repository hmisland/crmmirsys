<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Repositories\BaseRepository;

/**
 * Database-backed rate limiting for costly user actions (rate_limit_hits,
 * migration 0081). Survives sessions and is shared by every endpoint
 * that uses the same bucket.
 *
 * allowTestSend() applies the config messaging.test_sends limits (per
 * user AND per organization) to every real test message: test SMS,
 * provider test email and campaign test send.
 */
final class ActionRateLimiter extends BaseRepository
{
    public const BUCKET_TEST_SEND = 'messaging.test_send';

    /**
     * Records a hit when every limit allows it; records nothing otherwise.
     *
     * @param array<int, array{subject: string, max: int, window: int}> $limits
     */
    public function attempt(string $bucket, array $limits): bool
    {
        $now = time();
        foreach ($limits as $limit) {
            if ($limit['max'] <= 0) {
                return false;
            }
            $count = (int) ($this->findOne(
                'SELECT COUNT(*) AS c FROM rate_limit_hits WHERE bucket = :bucket AND subject = :subject AND created_at > :since',
                ['bucket' => $bucket, 'subject' => $limit['subject'], 'since' => date('Y-m-d H:i:s', $now - $limit['window'])]
            )['c'] ?? 0);
            if ($count >= $limit['max']) {
                return false;
            }
        }

        foreach ($limits as $limit) {
            $this->query(
                'INSERT INTO rate_limit_hits (bucket, subject, created_at) VALUES (:bucket, :subject, :created_at)',
                ['bucket' => $bucket, 'subject' => $limit['subject'], 'created_at' => date('Y-m-d H:i:s', $now)]
            );
        }

        if (random_int(1, 50) === 1) {
            $longest = max(array_column($limits, 'window'));
            $this->query('DELETE FROM rate_limit_hits WHERE bucket = :bucket AND created_at < :before', [
                'bucket' => $bucket, 'before' => date('Y-m-d H:i:s', $now - max(86400, $longest)),
            ]);
        }

        return true;
    }

    /** Limits for one real test message (SMS or email) by $user. */
    public function allowTestSend(array $user): bool
    {
        $config = (array) Config::get('messaging.test_sends', []);

        return $this->attempt(self::BUCKET_TEST_SEND, [
            ['subject' => 'user:' . (int) ($user['id'] ?? 0), 'max' => (int) ($config['per_user_max'] ?? 5), 'window' => (int) ($config['per_user_window_seconds'] ?? 600)],
            ['subject' => 'org:' . (int) ($user['organization_id'] ?? 0), 'max' => (int) ($config['per_organization_max'] ?? 20), 'window' => (int) ($config['per_organization_window_seconds'] ?? 3600)],
        ]);
    }
}
