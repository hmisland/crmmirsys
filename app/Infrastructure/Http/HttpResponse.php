<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/**
 * One HTTP response. $status is 0 when no response arrived at all.
 * $connectFailed: the request never reached the provider (DNS/connect
 * error) — safe to retry. $timedOut: it may or may not have been
 * processed — retrying could duplicate a send.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly ?string $error = null,
        public readonly bool $connectFailed = false,
        public readonly bool $timedOut = false,
    ) {
    }

    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
