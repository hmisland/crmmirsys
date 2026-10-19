<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Api;

use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Integrations\IntegrationConnection;

/**
 * Read-only WooCommerce REST API v3 client ({store}/wp-json/wc/v3/…).
 *
 * Credentials go in the Authorization header (HTTP Basic over HTTPS, the
 * method WooCommerce documents for HTTPS stores) — never in the query
 * string, so they cannot end up in URLs, server access logs or our own
 * logs. Errors are reduced to a translation key + "retryable" flag; the
 * response body is never logged or shown.
 */
final class WooCommerceClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    /**
     * @return array{items: array, total_pages: int}
     * @throws WooApiException
     */
    public function getList(IntegrationConnection $connection, string $path, array $query = []): array
    {
        $response = $this->request($connection, $path, $query);
        $items = $response->json();
        if (!is_array($items) || !array_is_list($items)) {
            throw new WooApiException('woocommerce.error.bad_response', true);
        }

        return ['items' => $items, 'total_pages' => (int) ($response->header('X-WP-TotalPages') ?? 1)];
    }

    /** @throws WooApiException */
    public function getOne(IntegrationConnection $connection, string $path): array
    {
        $item = $this->request($connection, $path, [])->json();
        if (!is_array($item)) {
            throw new WooApiException('woocommerce.error.bad_response', true);
        }

        return $item;
    }

    /** @throws WooApiException */
    private function request(IntegrationConnection $connection, string $path, array $query): \App\Infrastructure\Http\HttpResponse
    {
        $key = $connection->secret('consumer_key');
        $secret = $connection->secret('consumer_secret');
        $base = StoreUrl::normalize((string) $connection->config('store_url', ''));
        if ($key === null || $secret === null || $base === null) {
            throw new WooApiException('woocommerce.error.not_configured', false);
        }

        $url = $base . '/wp-json/wc/v3/' . ltrim($path, '/') . ($query !== [] ? '?' . http_build_query($query) : '');
        $response = $this->http->request('GET', $url, [
            'Authorization' => 'Basic ' . base64_encode($key . ':' . $secret),
            'Accept' => 'application/json',
            'User-Agent' => 'CRM-Group-Integration/1.0',
        ], null, $this->timeoutSeconds);

        if ($response->status === 0) {
            throw new WooApiException('woocommerce.error.unreachable', true);
        }
        if (in_array($response->status, [401, 403], true)) {
            throw new WooApiException('woocommerce.error.auth', false);
        }
        if ($response->status === 404) {
            throw new WooApiException('woocommerce.error.not_found', false);
        }
        if ($response->status === 429 || $response->status >= 500) {
            throw new WooApiException('woocommerce.error.unreachable', true);
        }
        if (!$response->ok()) {
            throw new WooApiException('woocommerce.error.bad_response', false);
        }

        return $response;
    }
}
