<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/** One claimed integration_jobs row. job_type: "sync" (incremental batch) or "webhook" (received event). */
final class IntegrationJob
{
    public const SYNC = 'sync';
    public const WEBHOOK = 'webhook';

    public function __construct(
        public readonly int $id,
        public readonly int $organizationId,
        public readonly int $connectionId,
        public readonly string $type,
        public readonly string $resource,
        public readonly ?string $externalId,
        public readonly ?string $payload,
        public readonly int $attempts,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['organization_id'],
            (int) $row['connection_id'],
            (string) $row['job_type'],
            (string) $row['resource'],
            $row['external_id'] !== null ? (string) $row['external_id'] : null,
            $row['payload'] !== null ? (string) $row['payload'] : null,
            (int) $row['attempts'],
        );
    }
}
