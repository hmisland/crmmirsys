<?php

declare(strict_types=1);

namespace App\Infrastructure\PublicLinks;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Issues and resolves public links (core migration 0086).
 *
 * Token: 32 random bytes, base64url (43 chars, 256 bits) — not derived
 * from any id, so it reveals nothing and cannot be guessed or
 * enumerated. Only sha256(token) is stored; the plain token exists only
 * in the URL handed to the customer (issue() returns it once).
 *
 * URL: {app.url}/p/{token}. Validity: status active, not past
 * expires_at, use_count < max_uses (when set). Every link belongs to one
 * organization; resolve() returns that organization id and callers run
 * under it — the token is the only input, so a link can never reach
 * another organization's data.
 */
final class PublicLinkService extends BaseRepository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_USED = 'used';

    public function __construct(private readonly string $appUrl)
    {
    }

    /**
     * Issues a link for the CURRENT organization.
     *
     * @return array{id: int, token: string, url: string}
     */
    public function issue(string $purpose, string $subjectType, int $subjectId, ?\DateTimeInterface $expiresAt, ?string $locale = null, ?int $maxUses = null): array
    {
        $token = self::newToken();
        $this->query(
            'INSERT INTO public_links (organization_id, token_hash, purpose, subject_type, subject_id, locale, status, expires_at, max_uses)
             VALUES (:org, :hash, :purpose, :stype, :sid, :locale, :status, :expires, :max)',
            [
                'org' => OrganizationContext::id(),
                'hash' => self::hash($token),
                'purpose' => $purpose,
                'stype' => $subjectType,
                'sid' => $subjectId,
                'locale' => $locale,
                'status' => self::STATUS_ACTIVE,
                'expires' => $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'max' => $maxUses,
            ]
        );
        $id = (int) $this->pdo()->lastInsertId();

        return ['id' => $id, 'token' => $token, 'url' => $this->url($token)];
    }

    public function url(string $token): string
    {
        return rtrim($this->appUrl, '/') . '/p/' . $token;
    }

    /** A valid link for this token, or null (unknown, malformed, revoked, expired or used up — callers show one generic page for all). */
    public function resolve(string $token): ?PublicLink
    {
        if (!self::wellFormed($token)) {
            return null;
        }
        $row = $this->findOne('SELECT * FROM public_links WHERE token_hash = :h', ['h' => self::hash($token)]);
        if ($row === null || $row['status'] !== self::STATUS_ACTIVE) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') <= time()) {
            return null;
        }
        if ($row['max_uses'] !== null && (int) $row['use_count'] >= (int) $row['max_uses']) {
            return null;
        }

        return PublicLink::fromRow($row);
    }

    public function markOpened(PublicLink $link): void
    {
        $this->query('UPDATE public_links SET first_opened_at = COALESCE(first_opened_at, :now) WHERE id = :id', ['now' => date('Y-m-d H:i:s'), 'id' => $link->id]);
    }

    /**
     * Counts one use atomically; false when the link was used up / revoked
     * meanwhile (a double submit loses the race instead of counting twice).
     */
    public function consume(PublicLink $link): bool
    {
        $stmt = $this->query(
            "UPDATE public_links
                SET use_count = use_count + 1, last_used_at = :now,
                    status = IF(max_uses IS NOT NULL AND use_count + 1 >= max_uses, 'used', status)
              WHERE id = :id AND status = 'active' AND (max_uses IS NULL OR use_count < max_uses)
                AND (expires_at IS NULL OR expires_at > :now2)",
            ['now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'), 'id' => $link->id]
        );

        return $stmt->rowCount() === 1;
    }

    /** Revokes every active link of a subject in the current organization (e.g. before re-sending an invitation). */
    public function revokeFor(string $subjectType, int $subjectId): int
    {
        return $this->query(
            "UPDATE public_links SET status = 'revoked', revoked_at = :now WHERE organization_id = :org AND subject_type = :t AND subject_id = :id AND status = 'active'",
            ['now' => date('Y-m-d H:i:s'), 'org' => OrganizationContext::id(), 't' => $subjectType, 'id' => $subjectId]
        )->rowCount();
    }

    public function revoke(int $linkId): void
    {
        $this->query(
            "UPDATE public_links SET status = 'revoked', revoked_at = :now WHERE id = :id AND organization_id = :org AND status = 'active'",
            ['now' => date('Y-m-d H:i:s'), 'id' => $linkId, 'org' => OrganizationContext::id()]
        );
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function wellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
