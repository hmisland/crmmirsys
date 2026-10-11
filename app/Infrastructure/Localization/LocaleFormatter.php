<?php

declare(strict_types=1);

namespace App\Infrastructure\Localization;

/**
 * Locale-aware presentation of numbers, dates and amounts in words, for
 * documents people print or keep (invoices, proformas). Pure functions,
 * no I/O — the rest of the UI keeps showing stored ISO values as before.
 *
 *  - digits:   fa → Persian (۰-۹), ar → Arabic-Indic (٠-٩), en/tr → Latin
 *  - numbers:  grouped per locale (fa/ar ٬ and ٫, tr . and ,, en , and .)
 *  - dates:    fa → Jalali (Solar Hijri) yyyy/mm/dd, others Gregorian
 *  - words:    integer amounts spelled out in fa / en / ar / tr
 *
 * Amounts are handled as decimal strings (never floats), the same rule
 * the Finance module uses for money.
 */
final class LocaleFormatter
{
    public function __construct(private readonly string $locale)
    {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function digits(string $text): string
    {
        return match ($this->locale) {
            'fa' => strtr($text, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']),
            'ar' => strtr($text, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']),
            default => $text,
        };
    }

    /** Groups a decimal string ("20900.000000000000000000") to $decimals places, rounding half up. */
    public function number(string $amount, int $decimals = 0): string
    {
        [$sign, $integer, $fraction] = $this->split($this->round($amount, $decimals));

        [$group, $point] = match ($this->locale) {
            'fa', 'ar' => ['٬', '٫'],
            'tr' => ['.', ','],
            'fr' => ["\u{00A0}", ','],
            default => [',', '.'],
        };

        // Digits are ASCII here, so byte-wise splitting is safe; the
        // (possibly multibyte) separator is only joined in afterwards.
        $head = strlen($integer) % 3;
        $chunks = $head > 0 ? [substr($integer, 0, $head)] : [];
        $chunks = array_merge($chunks, $head < strlen($integer) ? str_split(substr($integer, $head), 3) : []);
        $text = $sign . implode($group, $chunks) . ($decimals > 0 ? $point . str_pad($fraction, $decimals, '0') : '');

        return $this->digits($text);
    }

    /** A Y-m-d (or Y-m-d H:i:s) date for print: Jalali in Persian, Gregorian otherwise. */
    public function date(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return '—';
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        $text = match ($this->locale) {
            'fa' => vsprintf('%04d/%02d/%02d', self::gregorianToJalali($y, $mo, $d)),
            'tr' => sprintf('%02d.%02d.%04d', $d, $mo, $y),
            'fr' => sprintf('%02d/%02d/%04d', $d, $mo, $y),
            'ar' => sprintf('%04d/%02d/%02d', $y, $mo, $d),
            default => sprintf('%04d-%02d-%02d', $y, $mo, $d),
        };

        return $this->digits($text);
    }

    /** The integer part of an amount, spelled out. */
    public function words(string $amount): string
    {
        [, $integer] = $this->split($this->round($amount, 0));
        $integer = ltrim($integer, '0');

        if ($integer === '') {
            return match ($this->locale) {
                'fa' => 'صفر', 'ar' => 'صفر', 'tr' => 'sıfır', 'fr' => 'zéro', default => 'zero',
            };
        }

        // Split into groups of three from the right: [ones, thousands, millions, ...]
        $groups = array_map('intval', array_reverse(str_split(str_pad($integer, (int) ceil(strlen($integer) / 3) * 3, '0', STR_PAD_LEFT), 3)));

        return match ($this->locale) {
            'fa' => $this->wordsFa($groups),
            'ar' => $this->wordsAr($groups),
            'tr' => $this->wordsTr($groups),
            'fr' => $this->wordsFr($groups),
            default => $this->wordsEn($groups),
        };
    }

    /**
     * Gregorian → Jalali (Solar Hijri), the standard arithmetic
     * algorithm (as in jdf.scr.ir / the date picker's Jalali engine).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);

        return [$jy, $jm, $jd];
    }

    // ---------------------------------------------------------------

    private function round(string $amount, int $decimals): string
    {
        $amount = trim($amount) === '' ? '0' : trim($amount);
        $offset = '0.' . str_repeat('0', $decimals) . '5';

        return str_starts_with($amount, '-')
            ? bcsub($amount, $offset, $decimals)
            : bcadd($amount, $offset, $decimals);
    }

    /** @return array{0: string, 1: string, 2: string} sign, integer digits, fraction digits */
    private function split(string $amount): array
    {
        $sign = str_starts_with($amount, '-') ? '-' : '';
        [$integer, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        $integer = ltrim($integer, '0');

        if ($sign !== '' && trim($integer . $fraction, '0') === '') {
            $sign = '';
        }

        return [$sign, $integer === '' ? '0' : $integer, $fraction];
    }

    /** @param int[] $groups */
    private function wordsFa(array $groups): string
    {
        $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        $hundreds = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        $scales = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'تریلیارد'];

        $three = function (int $n) use ($ones, $tens, $hundreds): string {
            $parts = [];
            if ($n >= 100) { $parts[] = $hundreds[intdiv($n, 100)]; $n %= 100; }
            if ($n >= 20) { $parts[] = $tens[intdiv($n, 10)]; $n %= 10; }
            if ($n > 0) { $parts[] = $ones[$n]; }

            return implode(' و ', $parts);
        };

        $out = [];
        foreach ($groups as $i => $n) {
            if ($n > 0) {
                $out[] = trim($three($n) . ' ' . ($scales[$i] ?? ''));
            }
        }

        return implode(' و ', array_reverse($out));
    }

    /** @param int[] $groups */
    private function wordsEn(array $groups): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $scales = ['', 'thousand', 'million', 'billion', 'trillion', 'quadrillion'];

        $three = function (int $n) use ($ones, $tens): string {
            $parts = [];
            if ($n >= 100) { $parts[] = $ones[intdiv($n, 100)] . ' hundred'; $n %= 100; }
            if ($n >= 20) { $parts[] = $tens[intdiv($n, 10)] . ($n % 10 > 0 ? '-' . $ones[$n % 10] : ''); $n = 0; }
            if ($n > 0) { $parts[] = $ones[$n]; }

            return implode(' ', $parts);
        };

        $out = [];
        foreach ($groups as $i => $n) {
            if ($n > 0) {
                $out[] = trim($three($n) . ' ' . ($scales[$i] ?? ''));
            }
        }

        return implode(' ', array_reverse($out));
    }

    /** @param int[] $groups */
    private function wordsTr(array $groups): string
    {
        $ones = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];
        $tens = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];
        $scales = ['', 'bin', 'milyon', 'milyar', 'trilyon', 'katrilyon'];

        $three = function (int $n) use ($ones, $tens): string {
            $parts = [];
            $h = intdiv($n, 100);
            if ($h > 0) { $parts[] = ($h > 1 ? $ones[$h] . ' ' : '') . 'yüz'; }
            if (intdiv($n % 100, 10) > 0) { $parts[] = $tens[intdiv($n % 100, 10)]; }
            if ($n % 10 > 0) { $parts[] = $ones[$n % 10]; }

            return implode(' ', $parts);
        };

        $out = [];
        foreach ($groups as $i => $n) {
            if ($n === 0) {
                continue;
            }
            // Turkish says "bin", never "bir bin".
            $out[] = ($i === 1 && $n === 1) ? 'bin' : trim($three($n) . ' ' . ($scales[$i] ?? ''));
        }

        return implode(' ', array_reverse($out));
    }

    /** @param int[] $groups French: 1–99 with the 70/80/90 forms, "cent(s)", "mille", "million(s)". */
    private function wordsFr(array $groups): string
    {
        $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
            'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
        $tens = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

        $two = function (int $n) use ($units, $tens): string {
            if ($n < 20) {
                return $units[$n];
            }
            $t = intdiv($n, 10);
            $u = $n % 10;
            if ($t === 7 || $t === 9) {
                $u += 10; // soixante-dix…, quatre-vingt-dix…
            }
            if ($u === 0) {
                return $t === 8 ? 'quatre-vingts' : $tens[$t];
            }
            $joiner = ($u === 1 || $u === 11) && $t !== 8 && $t !== 9 ? ' et ' : '-';

            return $tens[$t] . $joiner . $units[$u];
        };

        $three = function (int $n, bool $plural) use ($two, $units): string {
            $h = intdiv($n, 100);
            $rest = $n % 100;
            $parts = [];
            if ($h > 0) {
                $parts[] = ($h > 1 ? $units[$h] . ' ' : '') . 'cent' . ($h > 1 && $rest === 0 && $plural ? 's' : '');
            }
            if ($rest > 0) {
                $word = $two($rest);
                // "quatre-vingts" loses its s when followed by another word (mille).
                $parts[] = !$plural && $rest === 80 ? 'quatre-vingt' : $word;
            }

            return implode(' ', $parts);
        };

        $scales = [1 => 'mille', 2 => 'million', 3 => 'milliard', 4 => 'billion', 5 => 'billiard'];
        $out = [];
        foreach ($groups as $i => $n) {
            if ($n === 0) {
                continue;
            }
            if ($i === 0) {
                $out[] = $three($n, true);
            } elseif ($i === 1) {
                $out[] = $n === 1 ? 'mille' : $three($n, false) . ' mille';
            } else {
                $out[] = $three($n, true) . ' ' . ($scales[$i] ?? '') . ($n > 1 ? 's' : '');
            }
        }

        return implode(' ', array_reverse($out));
    }

    /** @param int[] $groups */
    private function wordsAr(array $groups): string
    {
        $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة',
            'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
        $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
        $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
        // [singular, dual, plural (3–10), singular-accusative used after 11+]
        $scales = [
            1 => ['ألف', 'ألفان', 'آلاف', 'ألف'],
            2 => ['مليون', 'مليونان', 'ملايين', 'مليون'],
            3 => ['مليار', 'ملياران', 'مليارات', 'مليار'],
            4 => ['تريليون', 'تريليونان', 'تريليونات', 'تريليون'],
        ];

        $three = function (int $n) use ($ones, $tens, $hundreds): string {
            $parts = [];
            if ($n >= 100) { $parts[] = $hundreds[intdiv($n, 100)]; $n %= 100; }
            if ($n >= 20) {
                // Arabic reads units before tens: "واحد وعشرون".
                $parts[] = ($n % 10 > 0 ? $ones[$n % 10] . ' و' : '') . $tens[intdiv($n, 10)];
            } elseif ($n > 0) {
                $parts[] = $ones[$n];
            }

            return implode(' و', $parts);
        };

        $out = [];
        foreach ($groups as $i => $n) {
            if ($n === 0) {
                continue;
            }
            if ($i === 0) {
                $out[] = $three($n);
            } elseif ($n === 1) {
                $out[] = $scales[$i][0];
            } elseif ($n === 2) {
                $out[] = $scales[$i][1];
            } elseif ($n <= 10) {
                $out[] = $three($n) . ' ' . $scales[$i][2];
            } else {
                $out[] = $three($n) . ' ' . $scales[$i][3];
            }
        }

        return implode(' و', array_reverse($out));
    }
}
