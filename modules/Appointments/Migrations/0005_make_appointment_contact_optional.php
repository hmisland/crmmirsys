<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * The customer becomes optional, so internal meetings (no contact) can be
 * booked on the same calendar with the same conflict checks. The FK stays:
 * deleting a contact still deletes their appointments.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE appointments MODIFY contact_id INT UNSIGNED NULL');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DELETE FROM appointments WHERE contact_id IS NULL');
        $pdo->exec('ALTER TABLE appointments MODIFY contact_id INT UNSIGNED NOT NULL');
    }
};
