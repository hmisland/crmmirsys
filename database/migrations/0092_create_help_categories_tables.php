<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Help categories become editable by the Super Admin (until now a fixed
 * list in code): help_categories (key, order, "listed even while empty")
 * and help_category_translations (a name per language; English is the
 * fallback).
 *
 * Seeds the 20 categories 0091 used — FAQ, General, CRM platform and one per
 * module — with their current names in every language (read from lang/),
 * then links help_article_categories.category to help_categories.key:
 * deleting a category removes it from its articles (never the articles),
 * renaming a key follows through.
 *
 * Seeding skips keys that already exist, so it is safe to repeat.
 * Rollback drops the link and both tables (article categories stay).
 */
return new class implements MigrationInterface {
    /** key => [sort order, listed while empty, lang key of the name] */
    private const SEED = [
        'faq' => [10, 1, 'help.category.faq'],
        'general' => [20, 0, 'help.category.general'],
        'platform' => [30, 0, 'help.category.platform'],
        'sales' => [100, 0, 'catalog.module.sales.name'],
        'followups' => [110, 0, 'catalog.module.followups.name'],
        'client_records' => [120, 0, 'catalog.module.client_records.name'],
        'appointments' => [130, 0, 'catalog.module.appointments.name'],
        'woocommerce' => [200, 0, 'catalog.module.woocommerce.name'],
        'marketing' => [300, 0, 'catalog.module.marketing.name'],
        'marketing_email' => [310, 0, 'catalog.module.marketing_email.name'],
        'bale_messaging' => [320, 0, 'catalog.module.bale_messaging.name'],
        'customer_satisfaction' => [330, 0, 'catalog.module.customer_satisfaction.name'],
        'projects' => [400, 0, 'catalog.module.projects.name'],
        'contracts' => [410, 0, 'catalog.module.contracts.name'],
        'finance' => [500, 0, 'catalog.module.finance.name'],
        'wallet' => [510, 0, 'catalog.module.wallet.name'],
        'internal_mail' => [600, 0, 'catalog.module.internal_mail.name'],
        'notifications' => [610, 0, 'catalog.module.notifications.name'],
        'support' => [620, 0, 'catalog.module.support.name'],
        'knowledge' => [630, 0, 'catalog.module.knowledge.name'],
    ];

    private const LOCALES = ['en', 'fa', 'ar', 'tr', 'fr'];

    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE help_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(40) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                always_listed TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_help_categories_key (`key`),
                KEY idx_help_categories_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE help_category_translations (
                category_id INT UNSIGNED NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(100) NOT NULL,
                PRIMARY KEY (category_id, locale),
                CONSTRAINT fk_help_category_translations_category FOREIGN KEY (category_id) REFERENCES help_categories (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $lines = [];
        foreach (self::LOCALES as $locale) {
            $file = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
            $lines[$locale] = is_file($file) ? (array) require $file : [];
        }
        $exists = $pdo->prepare('SELECT id FROM help_categories WHERE `key` = :key');
        $insert = $pdo->prepare('INSERT INTO help_categories (`key`, sort_order, always_listed) VALUES (:key, :sort, :listed)');
        $name = $pdo->prepare('INSERT INTO help_category_translations (category_id, locale, name) VALUES (:id, :locale, :name)');
        foreach (self::SEED as $key => [$sort, $listed, $langKey]) {
            $exists->execute(['key' => $key]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $insert->execute(['key' => $key, 'sort' => $sort, 'listed' => $listed]);
            $id = (int) $pdo->lastInsertId();
            foreach (self::LOCALES as $locale) {
                $value = (string) ($lines[$locale][$langKey] ?? $lines['en'][$langKey] ?? $key);
                $name->execute(['id' => $id, 'locale' => $locale, 'name' => mb_substr($value, 0, 100)]);
            }
        }

        // Every category already used by an article must exist before the link.
        foreach ($pdo->query('SELECT DISTINCT category FROM help_article_categories')->fetchAll(\PDO::FETCH_COLUMN) as $orphan) {
            $exists->execute(['key' => $orphan]);
            if ($exists->fetchColumn() === false) {
                $insert->execute(['key' => $orphan, 'sort' => 900, 'listed' => 0]);
                $name->execute(['id' => (int) $pdo->lastInsertId(), 'locale' => 'en', 'name' => mb_substr((string) $orphan, 0, 100)]);
            }
        }
        $pdo->exec(
            'ALTER TABLE help_article_categories ADD CONSTRAINT fk_help_article_categories_category
             FOREIGN KEY (category) REFERENCES help_categories (`key`) ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE help_article_categories DROP FOREIGN KEY fk_help_article_categories_category');
        $pdo->exec('DROP TABLE IF EXISTS help_category_translations');
        $pdo->exec('DROP TABLE IF EXISTS help_categories');
    }
};
