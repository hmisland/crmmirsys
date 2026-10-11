<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * SMS providers become pluggable per country/language (like payment
 * gateways): an organization's own account records WHICH provider and
 * all of that provider's credential fields, as one encrypted JSON
 * object. The older api_key_encrypted/sender columns stay readable as a
 * fallback for rows saved before this migration (Kavenegar).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE organization_sms_settings
                ADD COLUMN provider VARCHAR(30) NOT NULL DEFAULT 'kavenegar' AFTER mode,
                ADD COLUMN credentials_encrypted TEXT NULL AFTER provider"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE organization_sms_settings DROP COLUMN credentials_encrypted, DROP COLUMN provider');
    }
};
