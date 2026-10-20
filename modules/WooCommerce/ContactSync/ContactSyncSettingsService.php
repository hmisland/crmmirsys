<?php

declare(strict_types=1);

namespace Modules\WooCommerce\ContactSync;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Repositories\TagRepository;

/**
 * Per-shop contact sync settings (Organization settings → Integrations →
 * shop → Contact sync). The route needs organization.edit; switching the
 * sync on also needs contacts.create + contacts.edit (it creates and
 * completes contacts on the user's behalf), and creating a missing
 * contact group needs contacts.manage_categories — the same keys the
 * Contacts pages use.
 *
 * The CRM gives a contact exactly one contact group (contacts.category_id);
 * extra labels are tags (many per contact), so a shop has one group plus
 * any number of tags.
 */
final class ContactSyncSettingsService
{
    private const MAX_TAGS = 10;

    public function __construct(
        private readonly ContactSyncRepository $sync,
        private readonly ContactCategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    /** The settings for the form (defaults when none are saved yet). */
    public function forForm(int $connectionId, string $storeUrl): array
    {
        $row = $this->sync->settings($connectionId);
        $tagNames = [];
        foreach (CustomerContactSync::tagIds($row) as $tagId) {
            $tag = $this->tags->findById($tagId);
            if ($tag !== null) {
                $tagNames[] = (string) $tag['name'];
            }
        }

        return [
            'enabled' => $row !== null && (int) $row['enabled'] === 1,
            'category_id' => $row['category_id'] ?? null,
            'tags' => implode('، ', $tagNames),
            'source' => (string) ($row['source'] ?? '') !== '' ? (string) $row['source'] : self::defaultSource($storeUrl),
            'mapping' => ContactFieldMapper::sanitize(json_decode((string) ($row['mapping_json'] ?? ''), true) ?? ContactFieldMapper::defaults()),
            'last_run_at' => $row['last_run_at'] ?? null,
            'last_run_summary' => json_decode((string) ($row['last_run_summary'] ?? ''), true) ?: null,
            'last_error' => $row['last_error'] ?? null,
            'saved' => $row !== null,
        ];
    }

    /** @return array<string, string> field => error translation key; [] = saved */
    public function save(array $user, int $connectionId, array $input, string $storeUrl): array
    {
        $errors = [];
        $enabled = (string) ($input['enabled'] ?? '') === '1';

        // Contact group: an existing one, or a new one (reused when the name already exists).
        $categoryId = null;
        $newCategory = trim((string) ($input['new_category'] ?? ''));
        if ($newCategory !== '') {
            if (!$this->authorization->can($user, Permission::CONTACTS_MANAGE_CATEGORIES)) {
                $errors['new_category'] = 'woocommerce.contacts.error.category_permission';
            } elseif (mb_strlen($newCategory) > 100) {
                $errors['new_category'] = 'woocommerce.contacts.error.category_name';
            } else {
                $categoryId = $this->findOrCreateCategory($newCategory);
            }
        } elseif ((int) ($input['category_id'] ?? 0) > 0) {
            $category = $this->categories->findById((int) $input['category_id']);
            if ($category === null) {
                $errors['category_id'] = 'woocommerce.contacts.error.category_required';
            } else {
                $categoryId = (int) $category['id'];
            }
        }
        if ($enabled && $categoryId === null && !isset($errors['new_category'])) {
            $errors['category_id'] = 'woocommerce.contacts.error.category_required';
        }
        if ($enabled && (!$this->authorization->can($user, Permission::CONTACTS_CREATE) || !$this->authorization->can($user, Permission::CONTACTS_EDIT))) {
            $errors['enabled'] = 'woocommerce.contacts.error.contacts_permission';
        }

        $tagNames = self::splitTags((string) ($input['tags'] ?? ''));
        if (count($tagNames) > self::MAX_TAGS || array_filter($tagNames, fn (string $n) => mb_strlen($n) > 100) !== []) {
            $errors['tags'] = 'woocommerce.contacts.error.tags';
        }
        $source = trim((string) ($input['source'] ?? ''));
        if (mb_strlen($source) > 100) {
            $errors['source'] = 'woocommerce.contacts.error.source';
        }
        $mapping = ContactFieldMapper::sanitize(is_array($input['map'] ?? null) ? $input['map'] : []);
        if ($mapping['email'] === '' && in_array($mapping['phone'], ['', 'landline'], true)) {
            // Without email and mobile no customer can be identified, so nothing could ever be created or matched.
            $errors['map'] = 'woocommerce.contacts.error.no_identifier_mapped';
        }
        if ($errors !== []) {
            return $errors;
        }

        $tagIds = [];
        foreach ($tagNames as $name) {
            $tagIds[] = (int) $this->tags->findOrCreate($name)['id'];
        }
        $before = $this->sync->settings($connectionId);
        $this->sync->saveSettings($connectionId, [
            'enabled' => $enabled ? 1 : 0,
            'category_id' => $categoryId,
            'tag_ids' => (string) json_encode(array_values(array_unique($tagIds))),
            'source' => $source !== '' ? $source : self::defaultSource($storeUrl),
            'mapping_json' => (string) json_encode($mapping),
            // New contacts belong to the user who switched the sync on.
            'owner_user_id' => $enabled ? (int) $user['id'] : ($before['owner_user_id'] ?? null),
            'updated_by_user_id' => (int) $user['id'],
            'last_error' => null,
        ]);
        if ($enabled) {
            // New settings apply to every customer of the shop (filling empty fields is safe to repeat).
            $this->sync->markAllPending($connectionId);
        }

        return [];
    }

    /** The shop's domain, e.g. "shop.example.com" for https://www.shop.example.com/store. */
    public static function defaultSource(string $storeUrl): string
    {
        $host = strtolower((string) parse_url($storeUrl, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return mb_substr($host !== '' ? $host : 'WooCommerce', 0, 100);
    }

    /** @return string[] */
    public static function splitTags(string $raw): array
    {
        $names = array_map(fn (string $n) => trim((string) preg_replace('/\s+/u', ' ', $n)), preg_split('/[,،]/u', $raw) ?: []);

        return array_values(array_unique(array_filter($names, fn (string $n) => $n !== '')));
    }

    private function findOrCreateCategory(string $name): int
    {
        foreach ($this->categories->all() as $category) {
            if (mb_strtolower(trim((string) $category['name'])) === mb_strtolower($name)) {
                return (int) $category['id'];
            }
        }

        return $this->categories->create($name);
    }
}
