<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Repositories\ApiTokenRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\BearerToken;

/**
 * Issues, authenticates and revokes Bearer access tokens for non-browser
 * clients. It only answers "which active user does this token belong to";
 * tenant context, module entitlement and RBAC are then applied by the
 * existing middleware exactly as for a session-authenticated request —
 * a token carries no organization id and no permissions of its own.
 *
 * Token design (Phase 0): one opaque, revocable, absolute-expiry token per
 * device label; no refresh token yet (see docs/phase0-multi-client-api.md).
 */
final class ApiTokenService
{
    public const DEVICE_NAME_MAX = 100;

    /** last_used_at is refreshed at most this often, to avoid a DB write per request. */
    private const TOUCH_INTERVAL_SECONDS = 300;

    /** Expired/revoked token rows are purged this long after they stopped working. */
    private const STALE_RETENTION_SECONDS = 30 * 86400;

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Device labels are descriptive metadata (so a lost device's token can be
     * recognised), not proof of device identity.
     *
     * @throws ValidationException
     */
    public static function normalizeDeviceName(mixed $value): string
    {
        $name = is_string($value) ? $value : '';
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw new ValidationException(['device_name' => ['This field is required.']]);
        }

        if (mb_strlen($name) > self::DEVICE_NAME_MAX) {
            throw new ValidationException(['device_name' => ['Must be at most ' . self::DEVICE_NAME_MAX . ' characters.']]);
        }

        return $name;
    }

    /**
     * $user must already be authenticated by AuthService (password, status and
     * email-verification checks, throttling). If the user is already at the
     * per-user device limit, the oldest active tokens are revoked to make room.
     *
     * @param array<string, mixed> $user
     * @return array{token_id: int, access_token: string, token_type: string, expires_at: string, expires_in: int, device_name: string}
     */
    public function issue(array $user, string $deviceName, ?string $ip, ?string $userAgent, ?int $ttlSeconds = null): array
    {
        $userId = (int) $user['id'];
        $ttl = $ttlSeconds ?? max(1, (int) Config::get('auth.api_token_ttl_days', 30)) * 86400;
        $now = time();
        $nowSql = gmdate('Y-m-d H:i:s', $now);

        $this->tokens->deleteStaleForUser($userId, gmdate('Y-m-d H:i:s', $now - self::STALE_RETENTION_SECONDS));

        $max = max(1, (int) Config::get('auth.api_token_max_per_user', 10));
        $active = $this->tokens->activeIdsOldestFirst($userId, $nowSql);
        if (count($active) >= $max) {
            $this->tokens->revokeIds($userId, array_slice($active, 0, count($active) - $max + 1), $nowSql);
        }

        $raw = BearerToken::generate();
        $expiresTs = $now + $ttl;

        $id = $this->tokens->create([
            'user_id' => $userId,
            'token_hash' => BearerToken::hash($raw),
            'device_name' => $deviceName,
            'user_agent' => $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, 255),
            'created_ip' => $ip,
            'created_at' => $nowSql,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresTs),
        ]);

        return [
            'token_id' => $id,
            'access_token' => $raw,
            'token_type' => 'Bearer',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresTs),
            'expires_in' => $ttl,
            'device_name' => $deviceName,
        ];
    }

    /**
     * Resolves a raw token to its user, or null for ANY reason it cannot be
     * used (malformed, unknown, expired, revoked, user missing or inactive) —
     * callers must not tell the client which one.
     *
     * @return array{user: array<string, mixed>, token_id: int}|null
     */
    public function authenticate(string $rawToken, ?string $ip = null): ?array
    {
        if (!BearerToken::isWellFormed($rawToken)) {
            return null;
        }

        $nowSql = gmdate('Y-m-d H:i:s');
        $row = $this->tokens->findActiveByHash(BearerToken::hash($rawToken), $nowSql);

        if ($row === null) {
            return null;
        }

        // Fresh read on every request (like the session path): a deactivated
        // account or changed role takes effect immediately.
        $user = $this->users->findById((int) $row['user_id']);

        if ($user === null || ($user['status'] ?? null) !== 'active') {
            return null;
        }

        $this->touchIfStale($row, $nowSql, $ip);

        return ['user' => $user, 'token_id' => (int) $row['id']];
    }

    public function revoke(int $tokenId, int $userId): bool
    {
        return $this->tokens->revoke($tokenId, $userId, gmdate('Y-m-d H:i:s'));
    }

    public function revokeAllForUser(int $userId): int
    {
        return $this->tokens->revokeAllForUser($userId, gmdate('Y-m-d H:i:s'));
    }

    /** @param array<string, mixed> $row */
    private function touchIfStale(array $row, string $nowSql, ?string $ip): void
    {
        $last = $row['last_used_at'] ?? null;

        try {
            if ($last !== null) {
                $lastTs = (new \DateTimeImmutable((string) $last, new \DateTimeZone('UTC')))->getTimestamp();
                if ($lastTs > time() - self::TOUCH_INTERVAL_SECONDS) {
                    return;
                }
            }

            $this->tokens->touch((int) $row['id'], $nowSql, $ip);
        } catch (\Throwable) {
            // Best effort only: bookkeeping must never fail an authenticated request.
        }
    }
}
