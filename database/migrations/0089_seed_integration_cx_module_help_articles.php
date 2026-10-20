<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Seeds 13 help articles from database/help-content/<slug>/ (<locale>.html
 * + meta.php), the same way 0082 seeded the Marketing guides: WooCommerce
 * (connect, order sync, contact sync, older orders, orders on the contact
 * page), shop-purchase segments, scheduled campaigns, the transactional
 * messages report, Customer satisfaction (getting started, test
 * invitation, results) and Modules & Features / activation requests.
 * Every guide in en, fa, ar, tr, fr. Data only — no table or column changes.
 *
 * Idempotent: an existing slug is left untouched, so articles the Super
 * Admin edited in /admin/help are never overwritten. down() removes only
 * these slugs (translations cascade).
 */
return new class implements MigrationInterface {
    /** slug => sort order (after the Marketing guides, which end at 80, and the Bale guide at 90). */
    private const ARTICLES = [
        'woocommerce-connect-store' => 100,
        'woocommerce-order-sync' => 101,
        'woocommerce-contact-sync' => 102,
        'woocommerce-import-older-orders' => 103,
        'woocommerce-contact-orders' => 104,
        'segments-shop-purchases' => 105,
        'marketing-scheduled-campaigns' => 106,
        'marketing-transactional-messages' => 107,
        'cx-getting-started' => 110,
        'cx-test-invitation' => 111,
        'cx-results-follow-ups' => 112,
        'modules-catalog' => 120,
        'modules-activation-request' => 121,
    ];

    public function up(\PDO $pdo): void
    {
        // Data only (no DDL), so one transaction: all articles or none.
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
