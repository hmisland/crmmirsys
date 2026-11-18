<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Bearer access tokens (migration 0094). Only the SHA-256 hash of a token is
 * stored — a leaked database dump never yields a usable token.
 *
 * Every timestamp is a UTC 'Y-m-d H:i:s' string computed by the caller in PHP
 * (never MySQL NOW()), matching the storage convention since migration 0083.
 * Like UserRepository this is deliberately NOT tenant scoped: a token is
 * resolved BEFORE any OrganizationContext exists, and the organization is
 * always derived from the user row, never from the token or the client.
 *
 * Note for maintainers: real (non-emulated) prepared statements are used, so
 * a named placeholder must never appear twice in one statement.
 */
final class ApiTokenRepository extends BaseRepository
{
    private const TABLE = 'api_access_tokens';

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->insertGetId(self::TABLE, $data);
    }

    public function findActiveByHash(string $tokenHash, string $now): ?array
    {
        return $this->findOne(
            'SELECT * FROM api_access_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL AND expires_at > :now',
            ['hash' => $tokenHash, 'now' => $now]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM api_access_tokens WHERE id = :id', ['id' => $id]);
    }

    public function touch(int $id, string $now, ?string $ip): void
    {
        $this->update(self::TABLE, ['last_used_at' => $now, 'last_used_ip' => $ip], 'id = :id', ['id' => $id]);
    }

    /** Revokes one token, only if it belongs to $userId and is still active. */
    public function revoke(int $id, int $userId, string $now): bool
    {
        return $this->update(
            self::TABLE,
            ['revoked_at' => $now],
            'id = :id AND user_id = :user_id AND revoked_at IS NULL',
            ['id' => $id, 'user_id' => $userId]
        ) > 0;
    }

    public function revokeAllForUser(int $userId, string $now): int
    {
        return $this->update(
            self::TABLE,
            ['revoked_at' => $now],
            'user_id = :user_id AND revoked_at IS NULL',
            ['user_id' => $userId]
        );
    }

    /** @return int[] ids of the user's still-usable tokens, oldest first */
    public function activeIdsOldestFirst(int $userId, string $now): array
    {
        $rows = $this->findMany(
            'SELECT id FROM api_access_tokens
             WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > :now
             ORDER BY created_at ASC, id ASC',
            ['user_id' => $userId, 'now' => $now]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @param int[] $ids */
    public function revokeIds(int $userId, array $ids, string $now): int
    {
        if ($ids === []) {
            return 0;
        }

        $params = ['revoked_at' => $now, 'user_id' => $userId];
        $placeholders = [];
        foreach (array_values($ids) as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
        }

        return $this->query(
            'UPDATE api_access_tokens SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND revoked_at IS NULL AND id IN (' . implode(', ', $placeholders) . ')',
            $params
        )->rowCount();
    }

    /** Housekeeping: drops this user's tokens that expired or were revoked before $before. */
    public function deleteStaleForUser(int $userId, string $before): int
    {
        return $this->delete(
            self::TABLE,
            'user_id = :user_id AND ((revoked_at IS NOT NULL AND revoked_at < :before_revoked) OR expires_at < :before_expired)',
            ['user_id' => $userId, 'before_revoked' => $before, 'before_expired' => $before]
        );
    }
}
