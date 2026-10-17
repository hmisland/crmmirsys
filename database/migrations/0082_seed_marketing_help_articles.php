<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Seeds the 21 Marketing help articles from database/help-content/<slug>/
 * (<locale>.html + meta.php), the same way 0078 seeded the Finance guides.
 * Data only — no table or column changes.
 *
 * Languages: every guide in en, fa, ar, tr, fr — EXCEPT the four Kavenegar
 * SMS guides (sms-*), which exist in Persian only: Kavenegar serves Iran.
 * Readers in other languages never see them (HelpArticleRepository hides
 * articles that have neither the reader's language nor English).
 *
 * Idempotent: an existing slug is left untouched, so articles the Super
 * Admin edited in /admin/help are never overwritten. down() removes only
 * these slugs (translations cascade).
 */
return new class implements MigrationInterface {
    /** slug => sort order (after the Finance guides, which end at 31). */
    private const ARTICLES = [
        'email-getting-started' => 40,
        'email-sender-signup' => 41,
        'email-domain-authentication' => 42,
        'email-sender-connect' => 43,
        'email-sender-free-plan' => 44,
        'email-brevo-connect' => 45,
        'email-inbox-placement' => 46,
        'marketing-email-basics' => 50,
        'marketing-email-writing' => 51,
        'marketing-unsubscribe-optout' => 52,
        'marketing-transactional-vs-marketing' => 53,
        'marketing-campaign-results' => 54,
        'sms-kavenegar-signup' => 60,
        'sms-kavenegar-account-modes' => 61,
        'sms-kavenegar-promotional-rules' => 62,
        'sms-first-campaign' => 63,
        'segments-introduction' => 70,
        'segments-dynamic-builder' => 71,
        'segments-static' => 72,
        'segments-in-campaigns' => 73,
        'marketing-permissions-rules' => 80,
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
