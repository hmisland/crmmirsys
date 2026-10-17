<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

use App\Infrastructure\Http\HttpClientInterface;

/**
 * Brevo API v3 (https://developers.brevo.com/, checked 2026-10):
 *  - base https://api.brevo.com/v3/, header "api-key: {key}";
 *  - GET account — validates the key (account email, plan);
 *  - GET senders — {"senders":[{"email","name","active"}]}: whether the
 *    sender address exists and is active (verified) in the account;
 *  - POST smtp/email — {sender:{name,email}, to:[{email,name}], subject,
 *    htmlContent, textContent, replyTo:{email}, headers, tags}
 *    → 201 {"messageId": "<...>"};
 *  - POST webhooks — {url, description, events[], type:"transactional",
 *    auth:{type:"bearer", token}} → 201 {"id"}.
 * Transactional webhook payloads carry "event" (delivered, hard_bounce,
 * soft_bounce, blocked, spam, invalid_email, unsubscribed, deferred, …)
 * and "message-id"; they are parsed by BrevoWebhookParser.
 *
 * An active sender does not prove the DOMAIN is authenticated (SPF/DKIM);
 * that is always reported as a warning for the organization to check.
 */
final class BrevoEmailProvider implements EmailProviderInterface
{
    public const KEY = 'brevo';
    private const BASE_URL = 'https://api.brevo.com/v3/';

    public const WEBHOOK_EVENTS = ['delivered', 'hardBounce', 'softBounce', 'blocked', 'spam', 'invalid', 'deferred', 'unsubscribed'];

    public function __construct(
        private readonly string $apiKey,
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
            self::CAP_REPLY_TO => true,
            self::CAP_TEXT_PART => true,
            self::CAP_CUSTOM_HEADERS => true,
            self::CAP_TAGS => true,
            self::CAP_DELIVERY_EVENTS => true,
            self::CAP_WEBHOOK_REGISTRATION => true,
            self::CAP_SENDER_CHECK => true,
        ];
    }

    public function testConnection(string $senderEmail): EmailConnectionResult
    {
        if (trim($this->apiKey) === '') {
            return EmailConnectionResult::failure('API key is missing.');
        }

        $account = $this->http->request('GET', self::BASE_URL . 'account', $this->headers());
        if (!$account->ok()) {
            return EmailConnectionResult::failure($account->status === 0
                ? 'Could not reach Brevo: ' . ($account->error ?? 'network error')
                : ProviderErrors::message($account, 'Brevo'), $account->status ?: null);
        }

        $json = $account->json() ?? [];
        $facts = array_filter([
            'account_email' => (string) ($json['email'] ?? ''),
            'company' => (string) ($json['companyName'] ?? ''),
            'plan' => (string) ($json['plan'][0]['type'] ?? ''),
        ], fn ($v) => $v !== '');

        $warnings = ['email_providers.warning_domain_auth'];
        $verified = null;
        $senders = $this->http->request('GET', self::BASE_URL . 'senders', $this->headers());
        if ($senders->ok()) {
            $verified = false;
            foreach ((array) (($senders->json() ?? [])['senders'] ?? []) as $sender) {
                if (strcasecmp((string) ($sender['email'] ?? ''), trim($senderEmail)) === 0) {
                    $verified = (bool) ($sender['active'] ?? false);
                    break;
                }
            }
            if ($verified === false) {
                $warnings[] = 'email_providers.warning_sender_not_active';
            }
        } else {
            $warnings[] = 'email_providers.warning_sender_unverifiable';
        }

        return new EmailConnectionResult(true, null, $facts, $verified, $warnings, $account->status);
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        if (trim($this->apiKey) === '') {
            return EmailSendResult::failure('API key is missing.');
        }

        $body = [
            'sender' => array_filter(['email' => $message->fromEmail, 'name' => $message->fromName], fn ($v) => $v !== ''),
            'to' => [array_filter(['email' => $message->toEmail, 'name' => $message->toName], fn ($v) => $v !== '')],
            'subject' => $message->subject,
            'htmlContent' => $message->html,
        ];
        if ($message->text !== '') {
            $body['textContent'] = $message->text;
        }
        if ($message->replyTo !== null && $message->replyTo !== '') {
            $body['replyTo'] = ['email' => $message->replyTo];
        }
        if ($message->headers !== []) {
            $body['headers'] = $message->headers;
        }
        if ($message->tags !== []) {
            $body['tags'] = array_values($message->tags);
        }

        $response = $this->http->request('POST', self::BASE_URL . 'smtp/email', $this->headers(), (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 20);
        if ($response->ok()) {
            $json = $response->json() ?? [];

            return EmailSendResult::accepted(isset($json['messageId']) ? (string) $json['messageId'] : null, $response->status);
        }

        return ProviderErrors::sendFailure($response, 'Brevo');
    }

    public function registerWebhook(string $url, string $bearerToken): EmailConnectionResult
    {
        $body = [
            'url' => $url,
            'description' => 'CRM delivery events',
            'events' => self::WEBHOOK_EVENTS,
            'type' => 'transactional',
            'auth' => ['type' => 'bearer', 'token' => $bearerToken],
        ];

        $response = $this->http->request('POST', self::BASE_URL . 'webhooks', $this->headers(), (string) json_encode($body, JSON_UNESCAPED_SLASHES));
        if (!$response->ok()) {
            return EmailConnectionResult::failure(ProviderErrors::message($response, 'Brevo'), $response->status ?: null);
        }

        return new EmailConnectionResult(true, null, ['webhook_id' => (string) (($response->json() ?? [])['id'] ?? '')]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'api-key' => trim($this->apiKey),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
