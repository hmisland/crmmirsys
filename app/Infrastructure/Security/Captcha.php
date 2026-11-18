<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Config\Config;

/**
 * Self-hosted image captcha for the guest auth forms (login, register).
 * No third-party service: the code is generated here, kept only in the
 * server-side session, and drawn with PHP's GD extension.
 *
 * - issue()  makes a fresh code and stores it in the session
 * - render() draws a code as PNG bytes
 * - verify() checks the user's answer; the stored code is ALWAYS
 *   discarded afterwards (one attempt per image), so a bot cannot
 *   keep guessing against the same image.
 *
 * Config (config/auth.php): captcha_enabled, captcha_ttl_seconds.
 * If GD is missing on the host, the captcha switches itself off
 * (isEnabled() = false) instead of locking every user out.
 */
final class Captcha
{
    private const SESSION_KEY = '_captcha';

    /** Digits only, without 0/1: easy to type on a Persian or English keyboard. */
    private const CHARSET = '23456789';
    private const LENGTH = 5;

    public const WIDTH = 170;
    public const HEIGHT = 56;

    public static function isEnabled(): bool
    {
        return (bool) Config::get('auth.captcha_enabled', true)
            && function_exists('imagecreatetruecolor')
            && function_exists('imagepng');
    }

    /** Creates a new code, stores it in the session and returns it. */
    public static function issue(): string
    {
        $code = '';
        $max = strlen(self::CHARSET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::CHARSET[random_int(0, $max)];
        }

        $_SESSION[self::SESSION_KEY] = ['code' => $code, 'issued_at' => time()];

        return $code;
    }

    /**
     * One-shot check: the stored code is removed whether or not the
     * answer is right. Accepts Persian/Arabic-Indic digits too.
     */
    public static function verify(string $input): bool
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($state) || !isset($state['code'], $state['issued_at'])) {
            return false;
        }

        $ttl = (int) Config::get('auth.captcha_ttl_seconds', 600);

        if ((time() - (int) $state['issued_at']) > $ttl) {
            return false;
        }

        $answer = self::normalize($input);

        return $answer !== '' && hash_equals((string) $state['code'], $answer);
    }

    /** Draws the code as a PNG image and returns the raw bytes. */
    public static function render(string $code): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 247, 247, 249));

        // Background noise: dots, then lines.
        for ($i = 0; $i < 260; $i++) {
            $c = imagecolorallocate($img, random_int(150, 225), random_int(150, 225), random_int(150, 225));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocate($img, random_int(140, 210), random_int(140, 210), random_int(140, 210));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        $font = dirname(__DIR__, 3) . '/themes/default/assets/fonts/vazirmatn/Vazirmatn-Bold.ttf';
        $useTtf = function_exists('imagettftext') && is_file($font);
        $step = (int) floor(($w - 24) / strlen($code));
        $x = 14;

        foreach (str_split($code) as $char) {
            $color = imagecolorallocate($img, random_int(0, 100), random_int(0, 100), random_int(0, 100));

            if ($useTtf) {
                imagettftext($img, random_int(24, 29), random_int(-16, 16), $x, random_int(38, 46), $color, $font, $char);
            } else {
                // Fallback without FreeType: small built-in bitmap font.
                imagestring($img, 5, $x + 4, random_int(14, 28), $char, $color);
            }

            $x += $step;
        }

        // A few lines over the text so it is harder to segment.
        for ($i = 0; $i < 3; $i++) {
            $c = imagecolorallocate($img, random_int(60, 160), random_int(60, 160), random_int(60, 160));
            imageline($img, random_int(0, 20), random_int(5, $h - 5), random_int($w - 20, $w), random_int(5, $h - 5), $c);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    private static function normalize(string $input): string
    {
        $input = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/\s+/', '', $input) ?? '';
    }
}
