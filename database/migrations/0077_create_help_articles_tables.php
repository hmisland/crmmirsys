<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Platform-wide help center: articles written by the Super Admin, read
 * by every user. Not tenant-scoped. One row per article (stable slug
 * that pages link to with help_link()), one translation row per locale
 * with the HTML body; en is the fallback when a locale is missing.
 *
 * Seeds the first article ("deal-contract-sales-project") from
 * database/help-content/<slug>/<locale>.html.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE help_articles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_help_articles_slug (slug),
                KEY idx_help_articles_order (is_published, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE help_article_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                article_id INT UNSIGNED NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(200) NOT NULL,
                summary VARCHAR(500) NULL,
                body MEDIUMTEXT NOT NULL,
                UNIQUE KEY uq_help_translation (article_id, locale),
                CONSTRAINT fk_help_translation_article FOREIGN KEY (article_id) REFERENCES help_articles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->seed($pdo, 'deal-contract-sales-project', 10);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS help_article_translations');
        $pdo->exec('DROP TABLE IF EXISTS help_articles');
    }

    private function seed(\PDO $pdo, string $slug, int $sortOrder): void
    {
        $dir = dirname(__DIR__) . '/help-content/' . $slug;
        $meta = is_file($dir . '/meta.php') ? require $dir . '/meta.php' : [];
        if ($meta === []) {
            return;
        }

        $pdo->prepare('INSERT INTO help_articles (slug, sort_order, is_published) VALUES (:slug, :sort, 1)')
            ->execute(['slug' => $slug, 'sort' => $sortOrder]);
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
    }
};
