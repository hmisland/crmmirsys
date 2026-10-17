<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * Normalizes Brevo transactional webhook payloads (one event object, or
 * a list when the webhook is "batched") into provider-neutral events:
 *
 *   ['key' => idempotency key, 'type' => delivered|hard_bounce|soft_bounce|
 *    blocked|complaint|invalid|deferred|unsubscribed, 'message_id',
 *    'email', 'occurred_at' (Y-m-d H:i:s UTC), 'reason']
 *
 * Events we don't track (opened, click, request, …) are dropped.
 * The key is built from message-id + event + event time, so a replayed
 * delivery of the same event maps to the same key.
 */
final class BrevoWebhookParser
{
    private const TYPES = [
        'delivered' => 'delivered',
        'hard_bounce' => 'hard_bounce',
        'hardBounce' => 'hard_bounce',
        'soft_bounce' => 'soft_bounce',
        'softBounce' => 'soft_bounce',
        'blocked' => 'blocked',
        'spam' => 'complaint',
        'invalid_email' => 'invalid',
        'invalid' => 'invalid',
        'deferred' => 'deferred',
        'unsubscribed' => 'unsubscribed',
    ];

    /** @return list<array{key: string, type: string, message_id: string, email: string, occurred_at: string, reason: string}> */
    public static function parse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = array_is_list($decoded) ? $decoded : [$decoded];
        $events = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = self::TYPES[(string) ($item['event'] ?? '')] ?? null;
            $messageId = trim((string) ($item['message-id'] ?? $item['message_id'] ?? ''));
            if ($type === null || $messageId === '') {
                continue;
            }

            $epoch = (int) ($item['ts_event'] ?? $item['ts_epoch'] ?? $item['ts'] ?? 0);
            if ($epoch > 20000000000) {
                $epoch = intdiv($epoch, 1000); // ts_epoch is in milliseconds
            }
            $occurred = $epoch > 0 ? gmdate('Y-m-d H:i:s', $epoch) : gmdate('Y-m-d H:i:s');

            $events[] = [
                'key' => hash('sha256', 'brevo|' . $messageId . '|' . $type . '|' . $epoch . '|' . (string) ($item['id'] ?? '')),
                'type' => $type,
                'message_id' => $messageId,
                'email' => strtolower(trim((string) ($item['email'] ?? ''))),
                'occurred_at' => $occurred,
                'reason' => mb_substr((string) ($item['reason'] ?? ''), 0, 250),
            ];
        }

        return $events;
    }
}
