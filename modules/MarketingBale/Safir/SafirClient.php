<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Safir;

/**
 * Safir send_message (official contract: https://docs.bale.ai/safir,
 * verified 2026-10-16):
 *
 *   POST https://safir.bale.ai/api/v3/send_message
 *   api-access-key: <organization key>
 *   {"request_id": "...", "bot_id": 123, "phone_number": "98…",
 *    "message_data": {"message": {"text": "..."}}}
 *   → {"message_id": "...", "error_data": null | [{phone_number, code, description}]}
 *
 * One recipient per request (there is no bulk endpoint). Text only in
 * this phase. request_id: Safir documents that a repeated request_id is
 * not sent again; the window/scope of that guarantee is NOT documented,
 * so callers never auto-resend an unknown outcome.
 *
 * Undocumented and therefore only assumed here: HTTP status codes
 * (401/403 = key rejected, 429 = rate limited, 5xx = server error).
 */
final class SafirClient
{
    public const DEFAULT_BASE_URL = 'https://safir.bale.ai/api/v3';

    public function __construct(
        private readonly SafirTransport $transport,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    public function sendText(string $apiKey, int $botId, string $phoneNumber, string $text, string $requestId): SafirOutcome
    {
        if ($apiKey === '' || $botId <= 0) {
            return new SafirOutcome(SafirOutcome::PAUSE, 'not_configured');
        }
        if (!SafirPhone::isSafirFormat($phoneNumber)) {
            return new SafirOutcome(SafirOutcome::FAILED, 'invalid_phone_format');
        }
        if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
            return new SafirOutcome(SafirOutcome::FAILED, 'invalid_text');
        }
        if ($requestId === '' || strlen($requestId) > 64) {
            return new SafirOutcome(SafirOutcome::FAILED, 'invalid_request_id');
        }

        try {
            $body = json_encode([
                'request_id' => $requestId,
                'bot_id' => $botId,
                'phone_number' => $phoneNumber,
                'message_data' => ['message' => ['text' => $text]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new SafirOutcome(SafirOutcome::FAILED, 'invalid_text');
        }

        $response = $this->transport->post(rtrim($this->baseUrl, '/') . '/send_message', [
            'api-access-key: ' . $apiKey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ], $body);

        return self::interpret($response);
    }

    /** Maps one raw HTTP result to an outcome (see SafirOutcome). Pure — unit-tested directly. */
    public static function interpret(SafirHttpResponse $response): SafirOutcome
    {
        if ($response->status === null) {
            return $response->requestSent
                ? new SafirOutcome(SafirOutcome::UNKNOWN, 'connection_lost_after_send')
                : new SafirOutcome(SafirOutcome::RETRY, 'connection_failed');
        }

        $status = $response->status;
        $decoded = null;
        $raw = (string) $response->body;
        if ($raw !== '' && strlen($raw) <= CurlSafirTransport::MAX_BODY) {
            try {
                $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }
        $decoded = is_array($decoded) ? $decoded : null;

        // A documented error code wins over the HTTP status.
        $errors = $decoded['error_data'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $first = is_array($errors[0] ?? null) ? $errors[0] : (is_array(reset($errors)) ? reset($errors) : []);
            $code = isset($first['code']) && is_numeric($first['code']) ? (int) $first['code'] : null;
            if ($code !== null && isset(SafirOutcome::CODES[$code])) {
                [$kind, $reason] = SafirOutcome::CODES[$code];

                return new SafirOutcome($kind, $reason, null, $code, $status);
            }

            return new SafirOutcome(SafirOutcome::UNKNOWN, 'safir_undocumented_error', null, $code, $status);
        }

        if ($status === 401 || $status === 403) {
            return new SafirOutcome(SafirOutcome::PAUSE, 'credentials_rejected', null, null, $status);
        }
        if ($status === 429) {
            return new SafirOutcome(SafirOutcome::RETRY, 'http_rate_limited', null, null, $status);
        }
        if ($status >= 500) {
            return new SafirOutcome(SafirOutcome::UNKNOWN, 'http_server_error', null, null, $status);
        }

        $messageId = $decoded['message_id'] ?? null;
        if ($status >= 200 && $status < 300) {
            if (is_string($messageId) && trim($messageId) !== '' && strlen($messageId) <= 255) {
                return new SafirOutcome(SafirOutcome::ACCEPTED, 'accepted', trim($messageId), null, $status);
            }

            return new SafirOutcome(SafirOutcome::UNKNOWN, 'malformed_response', null, null, $status);
        }
        if ($status >= 400) {
            return new SafirOutcome(SafirOutcome::FAILED, 'http_rejected', null, null, $status);
        }

        return new SafirOutcome(SafirOutcome::UNKNOWN, 'malformed_response', null, null, $status);
    }
}
