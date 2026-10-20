<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * help_categories + help_category_translations (migration 0092): the help
 * center's categories, managed by the Super Admin. Not tenant-scoped — the
 * same categories for every organization. Names resolve to the reader's
 * language, falling back to English, then to the key.
 */
final class HelpCategoryRepository extends BaseRepository
{
    public const FALLBACK_LOCALE = 'en';

    /**
     * Every category in display order, with its name in $locale and how many
     * articles it holds (all of them, published or not).
     *
     * @return list<array{id: int, key: string, sort_order: int, always_listed: int, name: string, article_count: int}>
     */
    public function all(string $locale): array
    {
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'sort_order' => (int) $r['sort_order'], 'always_listed' => (int) $r['always_listed'], 'article_count' => (int) $r['article_count']] + $r, $this->findMany(
            "SELECT c.id, c.`key`, c.sort_order, c.always_listed,
                    COALESCE(tl.name, tf.name, c.`key`) AS name,
                    (SELECT COUNT(*) FROM help_article_categories ac WHERE ac.category = c.`key`) AS article_count
             FROM help_categories c
             LEFT JOIN help_category_translations tl ON tl.category_id = c.id AND tl.locale = :locale
             LEFT JOIN help_category_translations tf ON tf.category_id = c.id AND tf.locale = :fallback
             ORDER BY c.sort_order, c.id",
            ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE]
        ));
    }

    /** @return array<string, string> key => name in $locale, display order */
    public function names(string $locale): array
    {
        return array_column($this->all($locale), 'name', 'key');
    }

    /** @return string[] keys in display order */
    public function keys(): array
    {
        return array_column($this->findMany('SELECT `key` FROM help_categories ORDER BY sort_order, id'), 'key');
    }

    public function find(int $id): ?array
    {
        return $this->findOne('SELECT * FROM help_categories WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, string> locale => name */
    public function translations(int $id): array
    {
        return array_column($this->findMany('SELECT locale, name FROM help_category_translations WHERE category_id = :id', ['id' => $id]), 'name', 'locale');
    }

    public function keyTaken(string $key, int $exceptId = 0): bool
    {
        return $this->findOne('SELECT 1 AS ok FROM help_categories WHERE `key` = :key AND id <> :id', ['key' => $key, 'id' => $exceptId]) !== null;
    }

    /**
     * Inserts or updates a category and replaces its names.
     *
     * @param array{key: string, sort_order: int, always_listed: bool} $category
     * @param array<string, string> $names locale => name (non-empty only)
     */
    public function save(?int $id, array $category, array $names): int
    {
        $work = function () use ($id, $category, $names): int {
            $columns = ['key' => $category['key'], 'sort_order' => $category['sort_order'], 'always_listed' => $category['always_listed'] ? 1 : 0];
            if ($id === null) {
                $id = $this->insertGetId('help_categories', $columns);
            } else {
                // A changed key follows through to the articles (ON UPDATE CASCADE).
                $this->update('help_categories', $columns, 'id = :id', ['id' => $id]);
                $this->delete('help_category_translations', 'category_id = :id', ['id' => $id]);
            }
            foreach ($names as $locale => $name) {
                $this->query('INSERT INTO help_category_translations (category_id, locale, name) VALUES (:id, :locale, :name)', ['id' => $id, 'locale' => $locale, 'name' => $name]);
            }

            return $id;
        };

        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    /** Deletes the category; its articles stay and only lose this category (ON DELETE CASCADE). */
    public function deleteCategory(int $id): void
    {
        $this->delete('help_categories', 'id = :id', ['id' => $id]);
    }
}
