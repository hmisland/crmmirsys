<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/** Every email provider the system can use, keyed by provider key. */
final class EmailProviderRegistry
{
    /** @var array<string, EmailProviderDefinition> */
    private array $providers = [];

    public function register(EmailProviderDefinition $provider): void
    {
        $this->providers[$provider->key] = $provider;
    }

    public function get(string $key): ?EmailProviderDefinition
    {
        return $this->providers[$key] ?? null;
    }

    /** @return EmailProviderDefinition[] */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
