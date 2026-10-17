<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * One email provider the system knows about, like SmsProviderDefinition.
 * The organization brings its own API credential; the factory builds the
 * adapter from it.
 */
final class EmailProviderDefinition
{
    /**
     * @param \Closure(string): EmailProviderInterface $factory builds the adapter from the API secret
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        private readonly \Closure $factory,
        public readonly string $secretLabelKey,
        public readonly string $docsUrl,
        public readonly string $signupUrl,
    ) {
    }

    public function make(string $secret): EmailProviderInterface
    {
        return ($this->factory)($secret);
    }
}
