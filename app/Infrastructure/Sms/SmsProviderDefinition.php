<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * One SMS provider the system knows about — like a payment gateway, a
 * provider serves particular countries/languages (Kavenegar: Iran,
 * Persian). Adding a provider for another country = one sender class
 * implementing SmsSenderInterface + one definition registered in
 * Application; its credential fields then appear automatically in
 * Organization settings → SMS for organizations in that language.
 */
final class SmsProviderDefinition
{
    /**
     * @param string[] $languages organization default_language values it serves, e.g. ['fa']
     * @param string[] $countries ISO country codes, informational
     * @param array<string, array{label: string, secret?: bool, required?: bool, hint?: string}> $fields
     *        credential fields; label/hint are translation keys
     * @param \Closure(array<string, string>): SmsSenderInterface $factory builds a sender from credentials
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $languages,
        public readonly array $countries,
        public readonly array $fields,
        private readonly \Closure $factory,
        public readonly ?string $signupUrl = null,
    ) {
    }

    /** @param array<string, string> $credentials */
    public function make(array $credentials): SmsSenderInterface
    {
        return ($this->factory)($credentials);
    }

    public function servesLanguage(string $language): bool
    {
        return in_array($language, $this->languages, true);
    }

    /** @return string[] names of required fields that are empty in $credentials */
    public function missingRequired(array $credentials): array
    {
        $missing = [];
        foreach ($this->fields as $name => $field) {
            if (!empty($field['required']) && trim((string) ($credentials[$name] ?? '')) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
