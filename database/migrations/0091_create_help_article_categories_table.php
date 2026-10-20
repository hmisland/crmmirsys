<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Help center categories: an article may belong to several categories
 * (App\Application\Services\HelpCategories: "faq", "general", "platform"
 * or a module key). Readers filter the help list by category.
 *
 * Also files the guides seeded so far (0078, 0082, 0089, and the Bale and
 * deal/contract guides) under their modules — only articles that exist,
 * INSERT IGNORE, so it is safe to repeat. Rollback drops the table.
 */
return new class implements MigrationInterface {
    /** slug pattern (SQL LIKE) => categories */
    private const ASSIGNMENTS = [
        'finance-%' => ['finance'],
        'deal-contract-sales-project' => ['sales', 'contracts', 'projects'],
        'email-%' => ['marketing', 'marketing_email'],
        'marketing-email-%' => ['marketing_email'],
        'marketing-%' => ['marketing'],
        'marketing-transactional-messages' => ['customer_satisfaction'],
        'sms-%' => ['marketing'],
        'segments-%' => ['marketing'],
        'segments-shop-purchases' => ['woocommerce'],
        'bale-%' => ['marketing', 'bale_messaging'],
        'woocommerce-%' => ['woocommerce'],
        'cx-%' => ['customer_satisfaction'],
        'modules-%' => ['platform'],
    ];

    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE help_article_categories (
                article_id INT UNSIGNED NOT NULL,
                category VARCHAR(40) NOT NULL,
                PRIMARY KEY (article_id, category),
                KEY idx_help_article_categories_category (category, article_id),
                CONSTRAINT fk_help_article_categories_article FOREIGN KEY (article_id) REFERENCES help_articles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $assign = $pdo->prepare('INSERT IGNORE INTO help_article_categories (article_id, category) SELECT id, :category FROM help_articles WHERE slug LIKE :slug');
        foreach (self::ASSIGNMENTS as $slug => $categories) {
            foreach ($categories as $category) {
                $assign->execute(['category' => $category, 'slug' => $slug]);
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS help_article_categories');
    }
};
