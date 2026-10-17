<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * Result of a credential/connection check. A successful login does NOT
 * mean the sender address or domain may send: $senderVerified is
 * true/false only when the provider's API can tell, null otherwise,
 * and $warnings lists what the organization still has to check.
 */
final class EmailConnectionResult
{
    /**
     * @param array<string, string> $account non-secret account facts (e.g. account email, plan)
     * @param string[] $warnings translation keys
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly array $account = [],
        public readonly ?bool $senderVerified = null,
        public readonly array $warnings = [],
        public readonly ?int $statusCode = null,
    ) {
    }

    public static function failure(string $error, ?int $statusCode = null): self
    {
        return new self(false, $error, [], null, [], $statusCode);
    }
}
