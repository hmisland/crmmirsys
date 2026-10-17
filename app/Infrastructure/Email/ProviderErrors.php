<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

use App\Infrastructure\Http\HttpResponse;

/**
 * Turns a failed provider HTTP response into an EmailSendResult with
 * the right retry semantics. Error text comes from the provider's own
 * JSON message; credentials never appear in it (they travel in headers,
 * which are never echoed).
 */
final class ProviderErrors
{
    public static function sendFailure(HttpResponse $response, string $provider): EmailSendResult
    {
        if ($response->status === 0) {
            $message = 'Could not reach ' . $provider . ': ' . ($response->error ?? 'network error');

            // Never reached the provider → safe to retry. Timed out → may have been sent.
            return EmailSendResult::failure($message, null, $response->connectFailed, $response->connectFailed ? 60 : null, !$response->connectFailed);
        }

        $message = self::message($response, $provider);

        if ($response->status === 429) {
            return EmailSendResult::failure($message, 429, true, self::retryAfter($response) ?? 60);
        }
        if ($response->status >= 500) {
            // The provider answered with an error; the message was not accepted.
            return EmailSendResult::failure($message, $response->status, true, 120);
        }

        return EmailSendResult::failure($message, $response->status);
    }

    public static function message(HttpResponse $response, string $provider): string
    {
        $json = $response->json() ?? [];
        $parts = [];
        if (isset($json['message']) && is_string($json['message'])) {
            $parts[] = $json['message'];
        }
        if (isset($json['errors']) && is_array($json['errors'])) {
            foreach ($json['errors'] as $field => $errors) {
                $parts[] = (is_string($field) ? $field . ': ' : '') . implode(', ', array_map('strval', (array) $errors));
            }
        }
        if (isset($json['code']) && is_string($json['code']) && $parts === []) {
            $parts[] = $json['code'];
        }

        $text = $parts !== [] ? implode(' — ', $parts) : 'Unexpected response from ' . $provider . '.';

        return mb_substr('HTTP ' . $response->status . ': ' . $text, 0, 480);
    }

    public static function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('retry-after') ?? $response->header('x-sib-ratelimit-reset');
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return max(1, min(3600, (int) $value));
    }
}
