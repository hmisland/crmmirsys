<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per the Phase 3 kickoff decision: any account created before this
 * phase (organization_id still NULL at the moment this migration
 * runs) was, by definition, using the platform when it had no
 * multi-tenancy at all — i.e. its sole operator. Promoting it to
 * Super Admin is the sensible one-time migration rather than
 * silently enrolling it into an auto-created organization it never
 * asked for. Every registration from this point forward goes through
 * AuthService::register()'s new organization-creation flow instead
 * and never hits this path.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('UPDATE users SET is_super_admin = 1 WHERE organization_id IS NULL');
    }

    public function down(\PDO $pdo): void
    {
        // Deliberately not reversible: we can't tell which currently
        // is_super_admin rows were set by this migration versus set
        // some other way afterward. Down is a no-op by design.
    }
};
