<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * One email provider account (an organization's own API key). Adding a
 * provider = one class implementing this + one EmailProviderDefinition
 * registered in Application; nothing in Marketing changes.
 *
 * Providers differ — capabilities() says what this one really supports,
 * so callers show "not supported" instead of silently ignoring a field.
 */
interface EmailProviderInterface
{
    public const CAP_REPLY_TO = 'reply_to';
    public const CAP_TEXT_PART = 'text_part';
    public const CAP_CUSTOM_HEADERS = 'custom_headers';
    public const CAP_TAGS = 'tags';
    public const CAP_DELIVERY_EVENTS = 'delivery_events';
    public const CAP_WEBHOOK_REGISTRATION = 'webhook_registration';
    public const CAP_SENDER_CHECK = 'sender_check';

    public function key(): string;

    /** @return array<string, bool> */
    public function capabilities(): array;

    /** Checks the credentials (and, where the API allows, the sender address). Sends nothing. */
    public function testConnection(string $senderEmail): EmailConnectionResult;

    public function send(EmailMessage $message): EmailSendResult;

    /**
     * Registers our delivery-event endpoint with the provider. Providers
     * without an API for it return a failure with 'unsupported'.
     */
    public function registerWebhook(string $url, string $bearerToken): EmailConnectionResult;
}
