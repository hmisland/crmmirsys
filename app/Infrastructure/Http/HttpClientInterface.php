<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/**
 * Minimal outbound HTTP for provider adapters (email today). Adapters
 * depend on this interface only, so tests swap in a fake transport and
 * never reach a real provider.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): HttpResponse;
}
