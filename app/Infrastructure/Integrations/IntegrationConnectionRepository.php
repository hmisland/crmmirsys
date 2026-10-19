<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * integration_connections (core migration 0085). Organization-facing
 * reads/writes carry an explicit organization_id from
 * OrganizationContext (credentials are only ever touched for the
 * organization being served, also under a Super Admin context — same
 * rule as BaleSettingsRepository). Two deliberate cross-organization
 * reads exist, for the unauthenticated webhook endpoint and the cron
 * worker; both return the row's own organization_id, which the caller
 * then runs under.
 */
final class IntegrationConnectionRepository extends BaseRepository
{
    private const TABLE = 'integration_connections';

    /** @return IntegrationConnection[] */
    public function forOrganization(?string $provider = null): array
    {
        $sql = 'SELECT * FROM integration_connections WHERE organization_id = :org';
        $params = ['org' => OrganizationContext::id()];
        if ($provider !== null) {
            $sql .= ' AND provider = :provider';
            $params['provider'] = $provider;
        }

        return array_map(fn (array $row) => new IntegrationConnection($row), $this->findMany($sql . ' ORDER BY provider, name, id', $params));
    }

    public function find(int $id): ?IntegrationConnection
    {
        $row = $this->findOne('SELECT * FROM integration_connections WHERE id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);

        return $row === null ? null : new IntegrationConnection($row);
    }

    /** Webhook endpoint: the key in the URL identifies the connection (any organization). */
    public function findByWebhookKey(string $key): ?IntegrationConnection
    {
        $row = $this->findOne('SELECT * FROM integration_connections WHERE webhook_key = :k', ['k' => $key]);

        return $row === null ? null : new IntegrationConnection($row);
    }

    /** Worker: a job's connection, by id, in the job's own organization. */
    public function findForJob(int $id, int $organizationId): ?IntegrationConnection
    {
        $row = $this->findOne('SELECT * FROM integration_connections WHERE id = :id AND organization_id = :org', ['id' => $id, 'org' => $organizationId]);

        return $row === null ? null : new IntegrationConnection($row);
    }

    /**
     * Worker: enabled connections whose last attempt is older than
     * $olderThan (or never), oldest first — the scheduler then applies
     * each provider's own interval and the entitlement check.
     *
     * @return IntegrationConnection[]
     */
    public function dueCandidates(string $olderThan, int $limit): array
    {
        $rows = $this->findMany(
            'SELECT * FROM integration_connections
             WHERE enabled = 1 AND credentials_encrypted IS NOT NULL
               AND (last_attempted_sync_at IS NULL OR last_attempted_sync_at < :before)
             ORDER BY last_attempted_sync_at IS NOT NULL, last_attempted_sync_at, id
             LIMIT ' . max(1, $limit),
            ['before' => $olderThan]
        );

        return array_map(fn (array $row) => new IntegrationConnection($row), $rows);
    }

    public function create(array $fields): int
    {
        unset($fields['id']);
        $fields['organization_id'] = OrganizationContext::id();

        return $this->insertGetId(self::TABLE, $fields);
    }

    public function updateFields(int $id, array $fields): void
    {
        unset($fields['id'], $fields['organization_id']);
        $this->update(self::TABLE, $fields, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    public function deleteConnection(int $id): void
    {
        $this->delete(self::TABLE, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    /** Worker/webhook bookkeeping, keyed by the connection's own organization. */
    public function touch(int $id, int $organizationId, array $fields): void
    {
        $this->update(self::TABLE, $fields, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => $organizationId]);
    }

    /** Merges $state into sync_state_json (read-modify-write; one worker at a time holds the lock). */
    public function mergeSyncState(IntegrationConnection $connection, array $state): void
    {
        $merged = array_replace($connection->syncState(), $state);
        $this->touch($connection->id(), $connection->organizationId(), ['sync_state_json' => json_encode($merged, JSON_UNESCAPED_SLASHES)]);
    }
}
