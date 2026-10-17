<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/** organization_email_providers (migration 0080) — tenant-scoped. */
final class OrganizationEmailProviderRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'organization_email_providers';
    }

    public function find(string $provider): ?array
    {
        return $this->tenantFindOne('SELECT * FROM organization_email_providers WHERE provider = :provider', ['provider' => $provider]);
    }

    /** @return array<string, array> keyed by provider */
    public function allForOrganization(): array
    {
        $rows = [];
        foreach ($this->tenantFindMany('SELECT * FROM organization_email_providers WHERE 1 = 1 ORDER BY provider') as $row) {
            $rows[(string) $row['provider']] = $row;
        }

        return $rows;
    }

    public function save(string $provider, array $fields): void
    {
        if ($this->find($provider) === null) {
            $this->tenantInsertGetId(array_merge(['provider' => $provider], $fields));

            return;
        }

        $this->tenantUpdate($fields, 'provider = :provider', ['provider' => $provider]);
    }

    /**
     * Webhook lookup happens before any organization is known, so it
     * is deliberately not tenant-scoped; the key is a random 48-char id.
     */
    public function findByWebhookKey(string $provider, string $key): ?array
    {
        if ($key === '' || strlen($key) > 64) {
            return null;
        }

        return $this->findOne(
            'SELECT * FROM organization_email_providers WHERE provider = :provider AND webhook_key = :webhook_key',
            ['provider' => $provider, 'webhook_key' => $key]
        );
    }
}
