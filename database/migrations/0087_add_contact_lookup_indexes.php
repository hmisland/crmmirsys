<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Lookup indexes for finding an existing contact by email or phone inside
 * one organization (first used by the WooCommerce customer → contact
 * sync, which looks a customer up before creating anyone). Without them
 * every lookup scans the organization's contacts.
 *
 * Indexes only: no column or data changes. Rollback drops them.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contacts ADD INDEX idx_contacts_org_email (organization_id, email(100)), ADD INDEX idx_contacts_org_phone (organization_id, phone)');
        $pdo->exec('ALTER TABLE contact_emails ADD INDEX idx_contact_emails_org_email (organization_id, email(100))');
        $pdo->exec('ALTER TABLE contact_phones ADD INDEX idx_contact_phones_org_number (organization_id, number)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE contact_phones DROP INDEX idx_contact_phones_org_number');
        $pdo->exec('ALTER TABLE contact_emails DROP INDEX idx_contact_emails_org_email');
        $pdo->exec('ALTER TABLE contacts DROP INDEX idx_contacts_org_phone, DROP INDEX idx_contacts_org_email');
    }
};
