<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Seeds the 12 Finance help articles (all five languages) from
 * database/help-content/<slug>/<locale>.html + meta.php, the same way
 * 0077 seeded the first guide. Data only — no table or column changes.
 *
 * Idempotent: a slug that already exists is left untouched, so articles
 * the Super Admin has edited in /admin/help are never overwritten.
 * down() removes only these slugs (their translations cascade).
 */
return new class implements MigrationInterface {
    /** slug => sort order (after the first guide, which has 10). */
    private const ARTICLES = [
        'finance-getting-started' => 20,
        'finance-proformas-invoices' => 21,
        'finance-amounts-discounts-tax' => 22,
        'finance-final-rounding' => 23,
        'finance-payments-balance' => 24,
        'finance-credit-notes-cancel' => 25,
        'finance-installments' => 26,
        'finance-expenses' => 27,
        'finance-project-finance' => 28,
        'finance-periods' => 29,
        'finance-reports' => 30,
        'finance-permissions-audit' => 31,
    ];

    public function up(\PDO $pdo): void
    {
        // Data only (no DDL), so one transaction: all 12 articles or none.
        $pdo->beginTransaction();
        try {
            foreach (self::ARTICLES as $slug => $sortOrder) {
                $this->seed($pdo, $slug, $sortOrder);
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
};
