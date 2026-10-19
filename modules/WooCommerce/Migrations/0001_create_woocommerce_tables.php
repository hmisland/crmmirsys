<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * The CRM's minimal copy of WooCommerce data — only what CRM features
 * need (customers, orders, order items, the products named in them),
 * never the whole shop database and never raw payloads.
 *
 * Every row keeps the store's own ids (external_*) and is unique per
 * connection (one store), which is what makes webhook + cron
 * processing idempotent: replays update the same row. external_modified_at
 * guards against an older payload overwriting newer data.
 *
 * Deleting a connection deletes its rows (FK cascade).
 *
 *  woo_customers.external_key: "id:{customer_id}" for registered
 *    customers, "guest:{sha1(email)}" for guest checkouts.
 *  woo_customers.contact_id: the CRM contact with the same email, when
 *    one exists (linked, never created automatically in this phase).
 *  Money columns follow the platform standard DECIMAL(38,18).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE woo_customers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                connection_id INT UNSIGNED NOT NULL,
                external_key VARCHAR(64) NOT NULL,
                external_id BIGINT UNSIGNED NULL,
                email VARCHAR(190) NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                phone VARCHAR(40) NULL,
                contact_id INT UNSIGNED NULL,
                external_modified_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_woo_customers_ext (connection_id, external_key),
                KEY idx_woo_customers_org_email (organization_id, email),
                KEY idx_woo_customers_contact (contact_id),
                CONSTRAINT fk_woo_customers_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_customers_connection FOREIGN KEY (connection_id) REFERENCES integration_connections (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_customers_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE woo_products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                connection_id INT UNSIGNED NOT NULL,
                external_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                sku VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_woo_products_ext (connection_id, external_id),
                KEY idx_woo_products_org (organization_id),
                CONSTRAINT fk_woo_products_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_products_connection FOREIGN KEY (connection_id) REFERENCES integration_connections (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE woo_orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                connection_id INT UNSIGNED NOT NULL,
                external_id BIGINT UNSIGNED NOT NULL,
                order_number VARCHAR(40) NOT NULL,
                customer_id INT UNSIGNED NULL,
                status VARCHAR(30) NOT NULL,
                currency VARCHAR(10) NULL,
                total DECIMAL(38,18) NOT NULL DEFAULT 0,
                billing_name VARCHAR(200) NULL,
                billing_email VARCHAR(190) NULL,
                billing_phone VARCHAR(40) NULL,
                external_created_at DATETIME NULL,
                external_modified_at DATETIME NULL,
                external_completed_at DATETIME NULL,
                status_changed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_woo_orders_ext (connection_id, external_id),
                KEY idx_woo_orders_org_status (organization_id, status),
                KEY idx_woo_orders_customer (customer_id),
                KEY idx_woo_orders_modified (connection_id, external_modified_at),
                CONSTRAINT fk_woo_orders_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_orders_connection FOREIGN KEY (connection_id) REFERENCES integration_connections (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_orders_customer FOREIGN KEY (customer_id) REFERENCES woo_customers (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE woo_order_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                external_id BIGINT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                external_product_id BIGINT UNSIGNED NULL,
                external_variation_id BIGINT UNSIGNED NULL,
                name VARCHAR(255) NOT NULL,
                sku VARCHAR(100) NULL,
                quantity INT NOT NULL DEFAULT 1,
                total DECIMAL(38,18) NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_woo_items_ext (order_id, external_id),
                KEY idx_woo_items_org (organization_id),
                KEY idx_woo_items_product (product_id),
                CONSTRAINT fk_woo_items_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_items_order FOREIGN KEY (order_id) REFERENCES woo_orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_items_product FOREIGN KEY (product_id) REFERENCES woo_products (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS woo_order_items');
        $pdo->exec('DROP TABLE IF EXISTS woo_orders');
        $pdo->exec('DROP TABLE IF EXISTS woo_products');
        $pdo->exec('DROP TABLE IF EXISTS woo_customers');
    }
};
