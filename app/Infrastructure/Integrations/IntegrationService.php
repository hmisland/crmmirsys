<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\SecretBox;

/**
 * Organization settings → Integrations: create / edit / enable /
 * disable / delete connections, test them and request a manual sync.
 *
 * Access: routes need organization.edit; every action here also needs
 * the provider's module entitlement for the current user (ModuleGate) —
 * the same "not purchased → card muted, POST refused" behaviour as the
 * messaging provider cards. All reads/writes are scoped to the current
 * organization (IntegrationConnectionRepository).
 *
 * Secrets: encrypted with SecretBox into one JSON blob; the UI only
 * ever gets a hint (last 4 chars of the first secret). A blank secret
 * field on edit keeps the stored value. The webhook secret is generated
 * here (random), never typed by the user.
 */
final class IntegrationService
{
    public const MAX_CONNECTIONS_PER_PROVIDER = 10;

    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationConnectionRepository $connections,
        private readonly IntegrationJobRepository $jobs,
        private readonly ModuleGate $gate,
    ) {
    }

    /** @return array<string, array{provider: IntegrationProviderInterface, entitled: bool, connections: array[]}> */
    public function overview(array $user): array
    {
        $byProvider = [];
        foreach ($this->connections->forOrganization() as $connection) {
            $byProvider[$connection->provider()][] = $connection->toView() + [
                'jobs' => $this->jobs->countsForConnection($connection->id(), $connection->organizationId()),
            ];
        }

        $overview = [];
        foreach ($this->registry->all() as $key => $provider) {
            $overview[$key] = [
                'provider' => $provider,
                'entitled' => $this->entitled($user, $provider),
                'connections' => $byProvider[$key] ?? [],
            ];
        }

        return $overview;
    }

    public function entitled(array $user, IntegrationProviderInterface $provider): bool
    {
        return $this->gate->allows($user, $provider->moduleKey(), null);
    }

    /**
     * @return array{id: ?int, errors: array<string, string>}
     */
    public function create(array $user, string $providerKey, array $input): array
    {
        $provider = $this->usableProvider($user, $providerKey);
        if (count($this->connections->forOrganization($providerKey)) >= self::MAX_CONNECTIONS_PER_PROVIDER) {
            return ['id' => null, 'errors' => ['_' => 'integrations.error.too_many']];
        }

        [$name, $config, $secrets, $errors] = $this->collect($provider, $input, true);
        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors];
        }

        $id = $this->connections->create([
            'provider' => $provider->key(),
            'name' => $name,
            'enabled' => 1,
            'status' => 'configured',
            'config_json' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'credentials_encrypted' => SecretBox::encrypt(json_encode($secrets, JSON_UNESCAPED_SLASHES)),
            'credentials_hint' => self::hint($secrets),
            'webhook_key' => bin2hex(random_bytes(20)),
            'webhook_secret_encrypted' => SecretBox::encrypt(bin2hex(random_bytes(24))),
            'created_by_user_id' => (int) $user['id'],
            'updated_by_user_id' => (int) $user['id'],
        ]);

        return ['id' => $id, 'errors' => []];
    }

    /** @return array<string, string> errors */
    public function update(array $user, int $id, array $input): array
    {
        $connection = $this->owned($id);
        $provider = $this->usableProvider($user, $connection->provider());

        [$name, $config, $secrets, $errors] = $this->collect($provider, $input, false);
        if ($errors !== []) {
            return $errors;
        }

        $fields = [
            'name' => $name,
            'config_json' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_by_user_id' => (int) $user['id'],
        ];
        if ($secrets !== []) {
            $merged = [];
            foreach ($provider->fields() as $field) {
                if ($field->isSecret()) {
                    $merged[$field->name] = $secrets[$field->name] ?? $connection->secret($field->name) ?? '';
                }
            }
            $fields['credentials_encrypted'] = SecretBox::encrypt(json_encode($merged, JSON_UNESCAPED_SLASHES));
            $fields['credentials_hint'] = self::hint($merged);
            $fields['status'] = 'configured';
            $fields['last_error'] = null;
        }
        // Other settings (e.g. another store URL) may mean other data: start the incremental cursors again.
        if ($connection->configArray() != $config) {
            $fields['sync_state_json'] = null;
        }
        $this->connections->updateFields($id, $fields);

        return [];
    }

    public function setEnabled(array $user, int $id, bool $enabled): void
    {
        $connection = $this->owned($id);
        $this->usableProvider($user, $connection->provider());
        $this->connections->updateFields($id, ['enabled' => $enabled ? 1 : 0, 'updated_by_user_id' => (int) $user['id']]);
    }

    /** Deleting a connection removes its queued jobs (FK cascade); the provider's synced rows are removed by the provider's own FK cascade. */
    public function delete(array $user, int $id): void
    {
        $connection = $this->owned($id);
        $this->usableProvider($user, $connection->provider());
        $this->connections->deleteConnection($id);
    }

    public function test(array $user, int $id): IntegrationResult
    {
        $connection = $this->owned($id);
        $provider = $this->usableProvider($user, $connection->provider());
        $result = $provider->testConnection($connection);
        $this->connections->updateFields($id, $result->ok
            ? ['status' => 'connected', 'last_error' => null]
            : ['status' => 'error', 'last_error' => $result->errorKey, 'last_error_at' => date('Y-m-d H:i:s')]);

        return $result;
    }

    /**
     * Manual sync: queues one incremental sync job per resource (no API
     * call in the request). Already-queued syncs are not duplicated.
     *
     * @return int jobs newly queued
     */
    public function requestSync(array $user, int $id): int
    {
        $connection = $this->owned($id);
        $provider = $this->usableProvider($user, $connection->provider());
        if (!$connection->enabled()) {
            throw new \DomainException('integrations.error.disabled');
        }

        $queued = 0;
        foreach ($provider->syncResources() as $resource) {
            $queued += $this->jobs->enqueue(
                $connection->organizationId(), $connection->id(), IntegrationJob::SYNC, $resource, null, null,
                IntegrationJobRepository::syncKey($connection->id(), $resource)
            ) ? 1 : 0;
        }

        return $queued;
    }

    /** Webhook URL + secret for the store's webhook settings (organization.edit only, shown on demand). */
    public function webhookDetails(array $user, int $id, string $appUrl): array
    {
        $connection = $this->owned($id);
        $this->usableProvider($user, $connection->provider());

        return [
            'provider' => $connection->provider(),
            'url' => rtrim($appUrl, '/') . '/webhooks/integrations/' . $connection->webhookKey(),
            'secret' => (string) $connection->webhookSecret(),
        ];
    }

    private function owned(int $id): IntegrationConnection
    {
        $connection = $this->connections->find($id);
        if ($connection === null || $connection->organizationId() !== OrganizationContext::id()) {
            throw new \App\Core\Exceptions\NotFoundException();
        }

        return $connection;
    }

    private function usableProvider(array $user, string $key): IntegrationProviderInterface
    {
        $provider = $this->registry->get($key);
        if ($provider === null) {
            throw new \App\Core\Exceptions\NotFoundException();
        }
        if (!$this->entitled($user, $provider)) {
            throw new \DomainException('integrations.error.module_unavailable');
        }

        return $provider;
    }

    /** @return array{0: string, 1: array, 2: array, 3: array<string, string>} name, config, filled secrets, errors */
    private function collect(IntegrationProviderInterface $provider, array $input, bool $isNew): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'integrations.error.name';
        }

        $config = [];
        $secrets = [];
        foreach ($provider->fields() as $field) {
            $value = trim((string) ($input[$field->name] ?? ''));
            if (mb_strlen($value) > $field->maxLength) {
                $errors[$field->name] = 'integrations.error.too_long';
                continue;
            }
            if ($field->isSecret()) {
                if ($value !== '') {
                    $secrets[$field->name] = $value;
                } elseif ($isNew && $field->required) {
                    $errors[$field->name] = 'integrations.error.required';
                }
                continue;
            }
            if ($value === '' && $field->required) {
                $errors[$field->name] = 'integrations.error.required';
                continue;
            }
            $config[$field->name] = $value;
        }

        if ($errors === []) {
            $errors = $provider->validate($config, $secrets, $isNew);
        }

        return [$name, $config, $secrets, $errors];
    }

    private static function hint(array $secrets): ?string
    {
        foreach ($secrets as $value) {
            if (is_string($value) && strlen($value) >= 8) {
                return '…' . substr($value, -4);
            }
        }

        return null;
    }
}
