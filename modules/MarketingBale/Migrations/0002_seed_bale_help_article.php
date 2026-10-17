<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Seeds the Bale (Safir) help article from database/help-content/bale-safir-setup/
 * (fa.html + meta.php), the same way core 0082 seeds the Marketing guides.
 * Data only — no table or column changes.
 *
 * Persian only (Bale/Safir serves Iran): readers in other languages never see it
 * (HelpArticleRepository hides articles with neither the reader's language nor English).
 *
 * Idempotent: an existing slug is left untouched, so a version the Super Admin
 * edited in /admin/help is never overwritten. down() removes only this slug.
 */
return new class implements MigrationInterface {
    private const SLUG = 'bale-safir-setup';
    private const SORT_ORDER = 90; // after every Marketing guide (core 0082 uses 40–80)

    public function up(\PDO $pdo): void
    {
        $exists = $pdo->prepare('SELECT 1 FROM help_articles WHERE slug = :slug');
        $exists->execute(['slug' => self::SLUG]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $dir = dirname(__DIR__, 3) . '/database/help-content/' . self::SLUG;
        $meta = is_file($dir . '/meta.php') ? require $dir . '/meta.php' : [];
        if ($meta === []) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO help_articles (slug, sort_order, is_published) VALUES (:slug, :sort, 1)')
                ->execute(['slug' => self::SLUG, 'sort' => self::SORT_ORDER]);
            $articleId = (int) $pdo->lastInsertId();

            $insert = $pdo->prepare(
                'INSERT INTO help_article_translations (article_id, locale, title, summary, body)
                 VALUES (:article, :locale, :title, :summary, :body)'
            );
            foreach ($meta as $locale => $info) {
                $file = $dir . '/' . $locale . '.html';
                if (!is_file($file)) {
                    continue;
                }
                $insert->execute([
                    'article' => $articleId,
                    'locale' => $locale,
                    'title' => $info['title'],
                    'summary' => $info['summary'],
                    'body' => (string) file_get_contents($file),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM help_articles WHERE slug = :slug')->execute(['slug' => self::SLUG]);
    }
};
