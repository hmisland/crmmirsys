<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Infrastructure\Security\SecretBox;

/**
 * One integration_connections row as providers see it. Secrets are
 * decrypted lazily and only through secret()/webhookSecret(); they are
 * kept out of var_dump/print_r/logs (__debugInfo) and never part of
 * toView(), which is the only shape a template receives.
 */
final class IntegrationConnection
{
    private ?array $secrets = null;

    public function __construct(private readonly array $row)
    {
    }

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    public function organizationId(): int
    {
        return (int) $this->row['organization_id'];
    }

    public function provider(): string
    {
        return (string) $this->row['provider'];
    }

    public function name(): string
    {
        return (string) $this->row['name'];
    }

    public function enabled(): bool
    {
        return (int) $this->row['enabled'] === 1;
    }

    public function status(): string
    {
        return (string) $this->row['status'];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->configArray()[$key] ?? $default;
    }

    public function configArray(): array
    {
        $decoded = json_decode((string) ($this->row['config_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function syncState(): array
    {
        $decoded = json_decode((string) ($this->row['sync_state_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function secret(string $name): ?string
    {
        if ($this->secrets === null) {
            $decoded = json_decode((string) (SecretBox::decrypt($this->row['credentials_encrypted'] ?? null) ?? ''), true);
            $this->secrets = is_array($decoded) ? $decoded : [];
        }
        $value = $this->secrets[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function webhookSecret(): ?string
    {
        return SecretBox::decrypt($this->row['webhook_secret_encrypted'] ?? null);
    }

    public function webhookKey(): string
    {
        return (string) $this->row['webhook_key'];
    }

    public function lastAttemptedSyncAt(): ?string
    {
        return $this->row['last_attempted_sync_at'] ?? null;
    }

    /** Safe projection for templates: no secret, no encrypted blob. */
    public function toView(): array
    {
        return [
            'id' => $this->id(),
            'provider' => $this->provider(),
            'name' => $this->name(),
            'enabled' => $this->enabled(),
            'status' => $this->status(),
            'config' => $this->configArray(),
            'credentials_hint' => $this->row['credentials_hint'] ?? null,
            'has_credentials' => !empty($this->row['credentials_encrypted']),
            'webhook_key' => $this->webhookKey(),
            'last_attempted_sync_at' => $this->row['last_attempted_sync_at'] ?? null,
            'last_successful_sync_at' => $this->row['last_successful_sync_at'] ?? null,
            'last_webhook_at' => $this->row['last_webhook_at'] ?? null,
            'last_error' => $this->row['last_error'] ?? null,
            'last_error_at' => $this->row['last_error_at'] ?? null,
        ];
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->row['id'] ?? null, 'provider' => $this->row['provider'] ?? null, 'organization_id' => $this->row['organization_id'] ?? null];
    }
}
