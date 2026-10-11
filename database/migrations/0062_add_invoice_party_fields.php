<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Fields an official invoice/proforma needs for both parties.
 *
 * Seller = the organization profile: address, postal code, phone,
 * national ID, economic code, registration number, and a logo (a
 * server-side path under storage/, never a public URL).
 * Buyer = the contact: national ID, economic code, postal code.
 *
 * All optional — an organization or contact without them still prints;
 * the empty rows are simply left out.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE organizations
                ADD COLUMN address VARCHAR(500) NULL AFTER default_timezone,
                ADD COLUMN postal_code VARCHAR(20) NULL AFTER address,
                ADD COLUMN phone VARCHAR(50) NULL AFTER postal_code,
                ADD COLUMN national_id VARCHAR(30) NULL AFTER phone,
                ADD COLUMN economic_code VARCHAR(30) NULL AFTER national_id,
                ADD COLUMN registration_number VARCHAR(50) NULL AFTER economic_code,
                ADD COLUMN logo_path VARCHAR(255) NULL AFTER registration_number"
        );

        $pdo->exec(
            "ALTER TABLE contacts
                ADD COLUMN national_id VARCHAR(30) NULL AFTER position,
                ADD COLUMN economic_code VARCHAR(30) NULL AFTER national_id,
                ADD COLUMN postal_code VARCHAR(20) NULL AFTER province"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts DROP COLUMN postal_code, DROP COLUMN economic_code, DROP COLUMN national_id');
        $pdo->exec(
            'ALTER TABLE organizations
                DROP COLUMN logo_path, DROP COLUMN registration_number, DROP COLUMN economic_code,
                DROP COLUMN national_id, DROP COLUMN phone, DROP COLUMN postal_code, DROP COLUMN address'
        );
    }
};
