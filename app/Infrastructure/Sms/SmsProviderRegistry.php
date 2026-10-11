<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/** Every SMS provider the system can use, keyed by provider key. */
final class SmsProviderRegistry
{
    /** @var array<string, SmsProviderDefinition> */
    private array $providers = [];

    public function register(SmsProviderDefinition $provider): void
    {
        $this->providers[$provider->key] = $provider;
    }

    public function get(string $key): ?SmsProviderDefinition
    {
        return $this->providers[$key] ?? null;
    }

    /** @return SmsProviderDefinition[] */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** @return SmsProviderDefinition[] providers serving organizations in this language */
    public function forLanguage(string $language): array
    {
        return array_values(array_filter($this->providers, fn (SmsProviderDefinition $p) => $p->servesLanguage($language)));
    }
}
