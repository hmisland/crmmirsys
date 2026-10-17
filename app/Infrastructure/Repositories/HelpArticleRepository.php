<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * help_articles + help_article_translations: the platform help center.
 * Not tenant-scoped — the Super Admin writes, every user reads. Reads
 * pick the requested locale and fall back to en per article.
 */
final class HelpArticleRepository extends BaseRepository
{
    public const FALLBACK_LOCALE = 'en';

    /** Articles in display order, each resolved to $locale (or en). */
    public function listForLocale(string $locale, bool $includeUnpublished, string $search = ''): array
    {
        // Readers only see articles that exist in their language or in English: a guide written for
        // one language only (e.g. the Kavenegar SMS guides, Persian/Iran only) never shows up as an
        // empty page in other languages. The Super Admin list still shows every article.
        $where = $includeUnpublished ? '1 = 1' : 'a.is_published = 1 AND (tl.article_id IS NOT NULL OR tf.article_id IS NOT NULL)';
        $params = ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE];
        if ($search !== '') {
            $where .= ' AND (COALESCE(tl.title, tf.title) LIKE :q OR COALESCE(tl.summary, tf.summary) LIKE :q2)';
            $params['q'] = '%' . $search . '%';
            $params['q2'] = '%' . $search . '%';
        }

        return $this->findMany(
            "SELECT a.id, a.slug, a.sort_order, a.is_published, a.updated_at,
                    COALESCE(tl.title, tf.title, a.slug) AS title,
                    COALESCE(tl.summary, tf.summary) AS summary,
                    (SELECT GROUP_CONCAT(x.locale ORDER BY x.locale) FROM help_article_translations x WHERE x.article_id = a.id) AS locales
             FROM help_articles a
             LEFT JOIN help_article_translations tl ON tl.article_id = a.id AND tl.locale = :locale
             LEFT JOIN help_article_translations tf ON tf.article_id = a.id AND tf.locale = :fallback
             WHERE {$where}
             ORDER BY a.sort_order, a.id",
            $params
        );
    }

    /** One article by slug, resolved to $locale (or en); null when missing or not written in either. */
    public function findBySlug(string $slug, string $locale): ?array
    {
        return $this->findOne(
            "SELECT a.id, a.slug, a.is_published, a.updated_at,
                    COALESCE(tl.title, tf.title, a.slug) AS title,
                    COALESCE(tl.summary, tf.summary) AS summary,
                    COALESCE(tl.body, tf.body, '') AS body
             FROM help_articles a
             LEFT JOIN help_article_translations tl ON tl.article_id = a.id AND tl.locale = :locale
             LEFT JOIN help_article_translations tf ON tf.article_id = a.id AND tf.locale = :fallback
             WHERE a.slug = :slug AND (tl.article_id IS NOT NULL OR tf.article_id IS NOT NULL)",
            ['slug' => $slug, 'locale' => $locale, 'fallback' => self::FALLBACK_LOCALE]
        );
    }

    public function find(int $id): ?array
    {
        return $this->findOne('SELECT * FROM help_articles WHERE id = :id', ['id' => $id]);
    }

    public function slugTaken(string $slug, int $exceptId = 0): bool
    {
        return $this->findOne(
            'SELECT 1 AS ok FROM help_articles WHERE slug = :slug AND id <> :id',
            ['slug' => $slug, 'id' => $exceptId]
        ) !== null;
    }

    /** @return array<string, array{title: string, summary: ?string, body: string}> keyed by locale */
    public function translations(int $articleId): array
    {
        $rows = $this->findMany(
            'SELECT locale, title, summary, body FROM help_article_translations WHERE article_id = :id',
            ['id' => $articleId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['locale']] = $row;
        }

        return $out;
    }

    /**
     * Inserts or updates the article and replaces its translations.
     *
     * @param array{slug: string, sort_order: int, is_published: bool} $article
     * @param array<string, array{title: string, summary: string, body: string}> $translations only non-empty locales
     */
    public function save(?int $id, array $article, array $translations, int $userId): int
    {
        $work = function () use ($id, $article, $translations, $userId) {
            $columns = [
                'slug' => $article['slug'],
                'sort_order' => $article['sort_order'],
                'is_published' => $article['is_published'] ? 1 : 0,
                'updated_by' => $userId,
            ];
            if ($id === null) {
                $id = $this->insertGetId('help_articles', $columns + ['created_by' => $userId]);
            } else {
                $this->update('help_articles', $columns, 'id = :id', ['id' => $id]);
                $this->delete('help_article_translations', 'article_id = :id', ['id' => $id]);
            }

            foreach ($translations as $locale => $tr) {
                $this->insertGetId('help_article_translations', [
                    'article_id' => $id,
                    'locale' => $locale,
                    'title' => $tr['title'],
                    'summary' => $tr['summary'] !== '' ? $tr['summary'] : null,
                    'body' => $tr['body'],
                ]);
            }

            return $id;
        };

        // Join the caller's transaction if one is already open.
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    public function deleteArticle(int $id): void
    {
        $this->delete('help_articles', 'id = :id', ['id' => $id]);
    }
}
