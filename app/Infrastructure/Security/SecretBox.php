<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Config\Config;

use function App\Infrastructure\Config\env;

/**
 * Symmetric encryption (AES-256-GCM) for secrets stored in the database,
 * e.g. an organization's own SMS API key.
 *
 * Key: APP_KEY from .env when set; otherwise derived from the database
 * credentials, so it works on an install that never defined APP_KEY.
 * Changing whichever of those is in use makes previously stored secrets
 * unreadable — decrypt() then returns null and the owner simply enters
 * the secret again.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt secret.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $appKey = (string) env('APP_KEY', '');
        if ($appKey !== '') {
            return hash('sha256', 'secretbox|' . $appKey, true);
        }

        $db = (array) Config::get('database.connections.' . Config::get('database.default'), []);

        return hash('sha256', 'secretbox|' . ($db['database'] ?? '') . '|' . ($db['username'] ?? '') . '|' . ($db['password'] ?? ''), true);
    }
}
