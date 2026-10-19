<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase 2 — transactional delivery (Core TransactionalDeliveryInterface,
 * implemented by Marketing): one row per message another module asked
 * Marketing to deliver (e.g. a Customer Satisfaction survey invitation).
 * It is both the idempotency record and the send history.
 *
 * reference: the caller's stable key (e.g. "cx_invitation:42"), unique
 * per organization — delivering the same reference twice never sends
 * twice.
 * status: processing (claimed, being sent) → sent (accepted by the
 * provider, NOT delivered) | retry (certainly not sent; the caller may
 * ask again) | failed (final; ambiguous = 1 when it may have gone out)
 * | skipped (opted out / recipient unsupported).
 * error_key: translation key returned to the caller; error: provider text.
 * billed_units: SMS parts already charged (never charged twice).
 * The message text is NOT stored: it can carry a single-use public link
 * whose token must exist only in the message itself.
 * New table only; rollback drops it.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_transactional_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                reference VARCHAR(120) NOT NULL,
                purpose VARCHAR(40) NOT NULL,
                channel VARCHAR(10) NOT NULL,
                address VARCHAR(255) NOT NULL,
                contact_id INT UNSIGNED NULL,
                recipient_name VARCHAR(200) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'processing',
                provider VARCHAR(40) NULL,
                provider_message_id VARCHAR(255) NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                billed_units SMALLINT UNSIGNED NULL,
                ambiguous TINYINT(1) NOT NULL DEFAULT 0,
                error_key VARCHAR(100) NULL,
                error VARCHAR(500) NULL,
                skip_reason VARCHAR(40) NULL,
                lock_token VARCHAR(32) NULL,
                locked_until DATETIME NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_marketing_transactional_reference (organization_id, reference),
                KEY idx_marketing_transactional_contact (contact_id),
                KEY idx_marketing_transactional_org_created (organization_id, created_at),
                CONSTRAINT fk_marketing_transactional_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_transactional_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_transactional_messages');
    }
};
