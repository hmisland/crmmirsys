<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

use App\Infrastructure\Http\HttpClientInterface;

/**
 * Sender (sender.net) API v2, per the official reference
 * (https://api.sender.net/, checked 2026-10):
 *  - base https://api.sender.net/v2/, HTTPS only;
 *  - auth header "Authorization: Bearer {token}";
 *  - POST message/send — {from:{email,name}, to:{email,name}, subject,
 *    html, text, headers, variables} → {"success": true, "emailId": "..."};
 *  - errors: 401 bad token, 422 {"message","errors":{field:[...]}},
 *    429 with Retry-After / X-RateLimit-* headers.
 *
 * Not offered by this API (reported as unsupported, never faked):
 *  - a reply-to field;
 *  - per-message delivery/bounce events (Sender's webhooks are account
 *    topics configured in its dashboard, without a documented payload);
 *  - checking whether the sender address is verified.
 * The connection test calls GET campaigns?limit=1 (the endpoint the
 * official authentication example uses); it reads, never sends.
 */
final class SenderNetEmailProvider implements EmailProviderInterface
{
    public const KEY = 'sender';
    private const BASE_URL = 'https://api.sender.net/v2/';

    public function __construct(
        private readonly string $apiToken,
        private readonly HttpClientInterface $http,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function capabilities(): array
    {
        return [
            self::CAP_REPLY_TO => false,
            self::CAP_TEXT_PART => true,
            self::CAP_CUSTOM_HEADERS => true,
            self::CAP_TAGS => false,
            self::CAP_DELIVERY_EVENTS => false,
            self::CAP_WEBHOOK_REGISTRATION => false,
            self::CAP_SENDER_CHECK => false,
        ];
    }

    public function testConnection(string $senderEmail): EmailConnectionResult
    {
        if (trim($this->apiToken) === '') {
            return EmailConnectionResult::failure('API token is missing.');
        }

        $response = $this->http->request('GET', self::BASE_URL . 'campaigns?limit=1', $this->headers());
        if ($response->status === 401 || $response->status === 403) {
            return EmailConnectionResult::failure(ProviderErrors::message($response, 'Sender'), $response->status);
        }
        if (!$response->ok()) {
            return EmailConnectionResult::failure($response->status === 0
                ? 'Could not reach Sender: ' . ($response->error ?? 'network error')
                : ProviderErrors::message($response, 'Sender'), $response->status ?: null);
        }

        return new EmailConnectionResult(true, null, [], null, ['email_providers.warning_sender_unverifiable'], $response->status);
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        if (trim($this->apiToken) === '') {
            return EmailSendResult::failure('API token is missing.');
        }

        $body = [
            'from' => ['email' => $message->fromEmail, 'name' => $message->fromName],
            'to' => array_filter(['email' => $message->toEmail, 'name' => $message->toName], fn ($v) => $v !== ''),
            'subject' => $message->subject,
            'html' => $message->html,
        ];
        if ($message->text !== '') {
            $body['text'] = $message->text;
        }
        if ($message->headers !== []) {
            $body['headers'] = $message->headers;
        }

        $response = $this->http->request('POST', self::BASE_URL . 'message/send', $this->headers(), (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 20);
        $json = $response->json() ?? [];

        if ($response->ok() && ($json['success'] ?? false) === true) {
            return EmailSendResult::accepted(isset($json['emailId']) ? (string) $json['emailId'] : null, $response->status);
        }
        if ($response->ok()) {
            // 2xx without "success": true — not accepted.
            return EmailSendResult::failure((string) ($json['message'] ?? 'Sender did not accept the message.'), $response->status);
        }

        return ProviderErrors::sendFailure($response, 'Sender');
    }

    public function registerWebhook(string $url, string $bearerToken): EmailConnectionResult
    {
        return EmailConnectionResult::failure('unsupported');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . trim($this->apiToken),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
