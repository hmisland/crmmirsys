<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Seeds the Projects (3), Tasks (1) and Follow-ups (1) help articles in all
 * five languages from database/help-content/<slug>/<locale>.html + meta.php,
 * the same way 0078/0082/0089 did, and files each under its help category
 * (help_article_categories, 0091). Data only — no table or column changes.
 *
 * Idempotent: a slug that already exists is left untouched (so an article
 * the Super Admin edited in /admin/help is never overwritten); the category
 * links are INSERT IGNORE. down() removes only these slugs (their
 * translations and category links cascade).
 */
return new class implements MigrationInterface {
    /** slug => [sort order, help categories] */
    private const ARTICLES = [
        'projects-getting-started' => [130, ['projects']],
        'projects-milestones-tasks' => [131, ['projects', 'sales']],
        'projects-dashboard-delays' => [132, ['projects']],
        'tasks-getting-started' => [133, ['sales']],
        'followups-getting-started' => [134, ['followups']],
    ];

    public function up(\PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            foreach (self::ARTICLES as $slug => [$sortOrder, $categories]) {
                $this->seed($pdo, $slug, $sortOrder);
                $this->categorize($pdo, $slug, $categories);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function down(\PDO $pdo): void
    {
        $delete = $pdo->prepare('DELETE FROM help_articles WHERE slug = :slug');
        foreach (array_keys(self::ARTICLES) as $slug) {
            $delete->execute(['slug' => $slug]);
        }
    }

    private function seed(\PDO $pdo, string $slug, int $sortOrder): void
    {
        $exists = $pdo->prepare('SELECT 1 FROM help_articles WHERE slug = :slug');
        $exists->execute(['slug' => $slug]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

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

    /** @param string[] $categories */
    private function categorize(\PDO $pdo, string $slug, array $categories): void
    {
        $assign = $pdo->prepare('INSERT IGNORE INTO help_article_categories (article_id, category) SELECT id, :category FROM help_articles WHERE slug = :slug');
        foreach ($categories as $category) {
            $assign->execute(['category' => $category, 'slug' => $slug]);
        }
    }
};
