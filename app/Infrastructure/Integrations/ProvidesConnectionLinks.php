<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * Optional companion of IntegrationProviderInterface: extra pages a
 * provider's module offers for one connection (e.g. WooCommerce → the
 * shop's contact sync settings). Organization settings → Integrations
 * shows them as buttons on the connection card; the module's own routes
 * do the access checks. Providers without it are unchanged.
 */
interface ProvidesConnectionLinks
{
    /**
     * @param array $connection IntegrationConnection::toView()
     * @return array<int, array{href: string, icon: string, label: string}> label = translation key
     */
    public function connectionLinks(array $connection): array;
}
