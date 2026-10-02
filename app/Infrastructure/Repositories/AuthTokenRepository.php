<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Only the SHA-256 hash of a token is ever stored — the raw token
 * exists only in the URL sent to the user's email and in memory long
 * enough to hash it. A leaked database dump therefore never yields a
 * usable token, the same principle as password hashing.
 */
final class AuthTokenRepository extends BaseRepository
{
    public function create(int $userId, string $type, string $tokenHash, string $expiresAt): int
    {
        return $this->insertGetId('auth_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findValid(string $tokenHash, string $type): ?array
    {
        return $this->findOne(
            'SELECT * FROM auth_tokens
             WHERE token_hash = :hash AND type = :type AND used_at IS NULL AND expires_at > :now',
            ['hash' => $tokenHash, 'type' => $type, 'now' => date('Y-m-d H:i:s')]
        );
    }

    public function markUsed(int $id): void
    {
        $this->update('auth_tokens', ['used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    /**
     * Issuing a new token of this type invalidates older, still-valid
     * ones for the same user — e.g. requesting a second password reset
     * link makes the first one dead, rather than leaving multiple
     * live tokens around.
     */
    public function invalidateAllOfType(int $userId, string $type): void
    {
        $this->query(
            'UPDATE auth_tokens SET used_at = :now
             WHERE user_id = :user_id AND type = :type AND used_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'user_id' => $userId, 'type' => $type]
        );
    }
}
