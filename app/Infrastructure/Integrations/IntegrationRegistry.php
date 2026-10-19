<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * The providers available on this installation, registered by modules
 * (e.g. WooCommerce). Organization settings → Integrations lists them;
 * whether an organization may USE one is the provider's moduleKey()
 * entitlement, checked by IntegrationService / the worker.
 */
final class IntegrationRegistry
{
    /** @var array<string, IntegrationProviderInterface> */
    private array $providers = [];

    public function register(IntegrationProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?IntegrationProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /** @return array<string, IntegrationProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
