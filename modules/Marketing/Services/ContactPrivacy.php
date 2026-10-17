<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

/**
 * Marketing 3.1 (F1): what a user without contacts.view may see of a
 * recipient — a masked address, no name, no link to the contact.
 * Applied in controllers, so unmasked data never reaches the template.
 */
final class ContactPrivacy
{
    public static function maskAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (str_contains($address, '@')) {
            [$local, $domain] = explode('@', $address, 2);

            return mb_substr($local, 0, 1) . '***@' . $domain;
        }
        $length = mb_strlen($address);

        return $length <= 4 ? '***' : mb_substr($address, 0, 4) . str_repeat('*', max(3, $length - 6)) . mb_substr($address, -2);
    }

    /**
     * @param array<int, array<string, mixed>> $rows message or suppression rows
     * @return array<int, array<string, mixed>>
     */
    public static function maskRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $row['address'] = self::maskAddress((string) ($row['address'] ?? ''));
            if (array_key_exists('recipient_name', $row)) {
                $row['recipient_name'] = '';
            }
            $row['contact_id'] = null;
            if (isset($row['error'])) {
                // Provider errors can quote the number/address.
                $row['error'] = preg_replace('/[\w.+-]+@[\w.-]+|\+?\d[\d\s-]{6,}\d/u', '***', (string) $row['error']);
            }

            return $row;
        }, $rows);
    }
}
