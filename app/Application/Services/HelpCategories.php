<?php

declare(strict_types=1);

namespace App\Application\Services;

use function App\Infrastructure\Theme\t;

/**
 * Help center categories: the fixed list the Super Admin picks from (an
 * article may belong to several) and readers filter by. Besides "faq",
 * "general" and "platform" (the CRM itself: modules, tickets, help), a
 * category is a module key and reuses that module's catalog name, so a
 * module needs no extra translation to become a help category.
 */
final class HelpCategories
{
    public const FAQ = 'faq';

    /** Display order. */
    public const KEYS = [
        self::FAQ, 'general', 'platform',
        'sales', 'followups', 'client_records', 'appointments',
        'woocommerce',
        'marketing', 'marketing_email', 'bale_messaging', 'customer_satisfaction',
        'projects', 'contracts',
        'finance', 'wallet',
        'internal_mail', 'notifications', 'support', 'knowledge',
    ];

    /** Shown in the reader's filter even while empty. */
    public const ALWAYS_LISTED = [self::FAQ];

    public static function valid(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function label(string $key): string
    {
        return in_array($key, [self::FAQ, 'general', 'platform'], true)
            ? t('help.category.' . $key)
            : t('catalog.module.' . $key . '.name');
    }

    /** @param string[] $keys @return string[] the valid ones, in display order */
    public static function normalize(array $keys): array
    {
        return array_values(array_intersect(self::KEYS, array_map('strval', $keys)));
    }
}
