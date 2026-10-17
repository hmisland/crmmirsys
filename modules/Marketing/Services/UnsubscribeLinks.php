<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Infrastructure\Security\SecretBox;

/**
 * Signed, unguessable unsubscribe links: /m/unsubscribe/{token}, where
 * token = base64url("orgId.messageId") . "." . base64url(HMAC-SHA256,
 * 16 bytes). The key is derived from the app secret (SecretBox), so a
 * link cannot be forged or pointed at another organization.
 */
final class UnsubscribeLinks
{
    public function __construct(private readonly string $appUrl)
    {
    }

    public function url(int $organizationId, int $messageId): string
    {
        return rtrim($this->appUrl, '/') . '/m/unsubscribe/' . self::token($organizationId, $messageId);
    }

    public static function token(int $organizationId, int $messageId): string
    {
        $payload = $organizationId . '.' . $messageId;

        return self::b64($payload) . '.' . self::b64(self::mac($payload));
    }

    /** @return array{0: int, 1: int}|null [organizationId, messageId] */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($token) > 200) {
            return null;
        }

        $payload = self::unb64($parts[0]);
        $mac = self::unb64($parts[1]);
        if ($payload === null || $mac === null || !hash_equals(self::mac($payload), $mac)) {
            return null;
        }
        if (preg_match('/^(\d+)\.(\d+)$/', $payload, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private static function mac(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, SecretBox::derivedKey('marketing-unsubscribe'), true), 0, 16);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
