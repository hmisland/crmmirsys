<?php

declare(strict_types=1);

namespace App\Application\Controllers\Api;

use App\Infrastructure\Pagination\Pagination;

/**
 * Turns contact rows into the public API shape. Platform-neutral on purpose
 * (web, Android, PWA, future iOS all read the same JSON).
 *
 * Rules: ids are integers; instants are ISO 8601 UTC ("...Z", the storage
 * convention since migration 0083); organization_id and other internal
 * columns are never exposed; a missing related record is null, not an
 * object of nulls. `updated_at` is included on purpose — it is the
 * version/ordering marker future offline sync will rely on.
 */
final class ContactApiPresenter
{
    /** 'Y-m-d H:i:s' (UTC) -> '2026-10-27T10:00:00Z'; null for empty/invalid. */
    public static function utc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, new \DateTimeZone('UTC'));
        $problems = \DateTimeImmutable::getLastErrors();

        // createFromFormat() "succeeds" on impossible dates such as
        // 0000-00-00 00:00:00 (it only records a warning): treat those as absent.
        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array<string, mixed> */
    public static function summary(array $row): array
    {
        $first = (string) ($row['first_name'] ?? '');
        $last = $row['last_name'] ?? null;

        return [
            'id' => (int) $row['id'],
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => trim($first . ' ' . (string) $last),
            'gender' => $row['gender'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'position' => $row['position'] ?? null,
            'city' => $row['city'] ?? null,
            'province' => $row['province'] ?? null,
            'country' => $row['country'] ?? null,
            'status' => $row['status'] ?? null,
            'company' => self::ref($row['company_id'] ?? null, $row['company_name'] ?? null),
            'category' => self::ref($row['category_id'] ?? null, $row['category_name'] ?? null),
            'owner_id' => isset($row['owner_id']) ? (int) $row['owner_id'] : null,
            'created_at' => self::utc($row['created_at'] ?? null),
            'updated_at' => self::utc($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed>|null $company  companies row or null
     * @param array<string, mixed>|null $category contact_categories row or null
     * @param array{phones?: array, emails?: array, social?: array, interests?: array} $related
     * @return array<string, mixed>
     */
    public static function detail(array $row, ?array $company, ?array $category, array $related): array
    {
        $row['company_name'] = $company['name'] ?? null;
        $row['category_name'] = $category['name'] ?? null;

        return self::summary($row) + [
            'address' => $row['address'] ?? null,
            'source' => $row['source'] ?? null,
            'description' => $row['description'] ?? null,
            'date_of_birth' => $row['date_of_birth'] ?? null,
            'team_id' => isset($row['team_id']) ? (int) $row['team_id'] : null,
            'phones' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'],
                'type' => $p['type'] ?? null,
                'number' => $p['number'] ?? null,
                'extension' => $p['extension'] ?? null,
                'is_primary' => (bool) ($p['is_primary'] ?? false),
            ], $related['phones'] ?? []),
            'emails' => array_map(static fn (array $e): array => [
                'id' => (int) $e['id'],
                'email' => $e['email'] ?? null,
                'is_primary' => (bool) ($e['is_primary'] ?? false),
            ], $related['emails'] ?? []),
            'social_profiles' => array_map(static fn (array $s): array => [
                'id' => (int) $s['id'],
                'platform' => $s['platform'] ?? null,
                'custom_label' => $s['custom_label'] ?? null,
                'value' => $s['value'] ?? null,
            ], $related['social'] ?? []),
            'interests' => array_map(static fn (array $i): string => (string) ($i['interest'] ?? ''), $related['interests'] ?? []),
        ];
    }

    /** @return array{page: int, per_page: int, total: int, last_page: int} */
    public static function paginationMeta(Pagination $pagination): array
    {
        return [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'total' => $pagination->total,
            'last_page' => $pagination->lastPage(),
        ];
    }

    /** @return array{id: int, name: string|null}|null */
    private static function ref(mixed $id, mixed $name): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ['id' => (int) $id, 'name' => $name === null ? null : (string) $name];
    }
}
