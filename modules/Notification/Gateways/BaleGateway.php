<?php

declare(strict_types=1);

namespace Modules\Notification\Gateways;

/**
 * Bale's Bot API (confirmed against the official docs.bale.ai
 * documentation, fetched directly rather than assumed): sendMessage
 * takes a `chat_id` (numeric chat ID or @channelusername) and `text`
 * (1-4096 chars). There is no documented way to message a user by
 * phone number alone — a bot can only message a chat_id that has
 * already started a conversation with it, the same restriction
 * Telegram bots have. `$destination` here is always that numeric
 * chat_id, collected from each user via their own
 * notification_recipients row.
 */
final class BaleGateway implements NotificationGatewayInterface
{
    public function __construct(private readonly string $botToken)
    {
    }

    public function channel(): string
    {
        return 'bale';
    }

    public function isConfigured(): bool
    {
        return trim($this->botToken) !== '';
    }

    public function send(string $destination, string $message): NotificationSendResult
    {
        if (!$this->isConfigured()) {
            return new NotificationSendResult(false, 'Bale bot token is not configured.');
        }

        $url = "https://tapi.bale.ai/bot{$this->botToken}/sendMessage";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['chat_id' => $destination, 'text' => $message]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return new NotificationSendResult(false, 'Could not reach Bale: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (empty($decoded['ok'])) {
            return new NotificationSendResult(false, $decoded['description'] ?? 'Bale rejected the message.');
        }

        return new NotificationSendResult(true, null);
    }
}
