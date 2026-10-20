<?php

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Presentation metadata for the Modules & Features catalog only: which
 * category a registered module is shown in, where an active module opens,
 * and which keys are never offered. It adds nothing to the module
 * registry — keys, names, dependencies, entitlement and activation stay
 * exactly as the modules table / module.json define them. A module that is
 * not listed here still appears (under "other"), so new modules need no
 * catalog change to be visible.
 */
final class ModuleCatalog
{
    /** Category key => modules shown in it, in display order. */
    public const CATEGORIES = [
        'sales_customers' => ['sales', 'followups', 'client_records', 'appointments'],
        'ecommerce' => ['woocommerce'],
        'marketing_cx' => ['marketing', 'marketing_email', 'bale_messaging', 'customer_satisfaction'],
        'projects_contracts' => ['projects', 'contracts'],
        'finance' => ['finance', 'wallet'],
        'communication' => ['internal_mail', 'notifications', 'support', 'knowledge'],
    ];

    public const OTHER = 'other';

    /** Registered modules that are not products (development samples). */
    public const HIDDEN = ['demo'];

    /** Where an ACTIVE module opens (the module's own routes keep their own access checks). */
    public const OPEN_URL = [
        'sales' => '/deals/board',
        'followups' => '/followups',
        'client_records' => '/client-records',
        'appointments' => '/appointments',
        'woocommerce' => '/org/integrations#provider-woocommerce',
        'marketing' => '/marketing',
        'marketing_email' => '/org/messaging',
        'bale_messaging' => '/org/messaging',
        'customer_satisfaction' => '/cx',
        'projects' => '/projects',
        'contracts' => '/contracts',
        'finance' => '/finance/invoices',
        'wallet' => '/wallet',
        'internal_mail' => '/mail',
        'support' => '/support/tickets/board',
        'knowledge' => '/kb/articles',
    ];

    /**
     * Highlights (translation keys) shown on a module's card — only for
     * capabilities that exist in this code base.
     */
    public const HIGHLIGHTS = [
        'woocommerce' => [
            'catalog.woocommerce.point.stores',
            'catalog.woocommerce.point.sync',
            'catalog.woocommerce.point.contacts',
            'catalog.woocommerce.point.orders_panel',
            'catalog.woocommerce.point.segments',
            'catalog.woocommerce.point.surveys',
        ],
    ];

    public static function categoryOf(string $key): string
    {
        foreach (self::CATEGORIES as $category => $keys) {
            if (in_array($key, $keys, true)) {
                return $category;
            }
        }

        return self::OTHER;
    }

    /** Position inside its category (unlisted modules last, by name). */
    public static function position(string $key): int
    {
        $keys = self::CATEGORIES[self::categoryOf($key)] ?? [];
        $index = array_search($key, $keys, true);

        return $index === false ? PHP_INT_MAX : (int) $index;
    }
}
