<?php

declare(strict_types=1);

namespace App\Infrastructure\PublicLinks;

/** One resolved, currently valid public_links row (server-side only: never rendered as-is). */
final class PublicLink
{
    public function __construct(
        public readonly int $id,
        public readonly int $organizationId,
        public readonly string $purpose,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?string $locale,
        public readonly ?string $expiresAt,
        public readonly ?int $maxUses,
        public readonly int $useCount,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['organization_id'],
            (string) $row['purpose'],
            (string) $row['subject_type'],
            (int) $row['subject_id'],
            $row['locale'] !== null ? (string) $row['locale'] : null,
            $row['expires_at'] ?? null,
            $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            (int) $row['use_count'],
        );
    }
}
