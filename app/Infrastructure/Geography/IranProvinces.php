<?php

declare(strict_types=1);

namespace App\Infrastructure\Geography;

/**
 * The 31 provinces are complete and confirmed against multiple current
 * sources. The city lists per province are NOT an exhaustive database
 * of Iran's 1000+ cities — verifying that at this scale without a
 * dedicated, licensed dataset risks real inaccuracy, so this is
 * deliberately a starter list (each province's capital, plus a few
 * well-known cities for provinces where that was readily confirmable).
 * The City field in the UI is free text with these as <datalist>
 * suggestions, never a closed dropdown — so no real city is ever
 * unselectable just because it's missing here. Extend PROVINCE_CITIES
 * freely; nothing else needs to change to support more entries.
 */
final class IranProvinces
{
    public const PROVINCE_CITIES = [
        'آذربایجان شرقی' => ['تبریز', 'مراغه', 'مرند', 'میانه'],
        'آذربایجان غربی' => ['ارومیه', 'خوی', 'میاندوآب', 'مهاباد'],
        'اردبیل' => ['اردبیل', 'پارس‌آباد', 'مشگین‌شهر', 'خلخال'],
        'اصفهان' => ['اصفهان', 'کاشان', 'نجف‌آباد', 'خمینی‌شهر'],
        'البرز' => ['کرج'],
        'ایلام' => ['ایلام'],
        'بوشهر' => ['بوشهر'],
        'تهران' => ['تهران', 'اسلامشهر', 'شهریار', 'ورامین'],
        'چهارمحال و بختیاری' => ['شهرکرد'],
        'خراسان جنوبی' => ['بیرجند'],
        'خراسان رضوی' => ['مشهد', 'نیشابور', 'سبزوار', 'تربت حیدریه'],
        'خراسان شمالی' => ['بجنورد'],
        'خوزستان' => ['اهواز', 'آبادان', 'دزفول', 'خرمشهر'],
        'زنجان' => ['زنجان'],
        'سمنان' => ['سمنان', 'شاهرود', 'دامغان'],
        'سیستان و بلوچستان' => ['زاهدان', 'چابهار', 'ایرانشهر'],
        'فارس' => ['شیراز', 'مرودشت', 'جهرم', 'کازرون'],
        'قزوین' => ['قزوین'],
        'قم' => ['قم'],
        'کردستان' => ['سنندج', 'سقز', 'مریوان'],
        'کرمان' => ['کرمان', 'سیرجان', 'رفسنجان', 'جیرفت'],
        'کرمانشاه' => ['کرمانشاه', 'اسلام‌آباد غرب', 'هرسین', 'کنگاور'],
        'کهگیلویه و بویراحمد' => ['یاسوج', 'دوگنبدان', 'دهدشت'],
        'گلستان' => ['گرگان', 'گنبدکاووس', 'علی‌آباد کتول', 'بندر ترکمن'],
        'گیلان' => ['رشت', 'بندرانزلی', 'لاهیجان', 'لنگرود'],
        'لرستان' => ['خرم‌آباد', 'بروجرد', 'دورود', 'کوهدشت'],
        'مازندران' => ['ساری', 'بابل', 'آمل', 'قائم‌شهر'],
        'مرکزی' => ['اراک'],
        'هرمزگان' => ['بندرعباس'],
        'همدان' => ['همدان'],
        'یزد' => ['یزد'],
    ];

    /** @return string[] all 31 province names, in the order above */
    public static function provinceNames(): array
    {
        return array_keys(self::PROVINCE_CITIES);
    }

    /** @return string[] every suggested city across every province, for a flat datalist */
    public static function allSuggestedCities(): array
    {
        $all = [];
        foreach (self::PROVINCE_CITIES as $cities) {
            $all = array_merge($all, $cities);
        }

        return array_values(array_unique($all));
    }
}
