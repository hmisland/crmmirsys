<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Weekly working hours per resource. Several rows per weekday are
 * allowed (split shifts); a weekday with no rows is a closed day.
 * weekday follows PHP's date('w'): 0 = Sunday ... 6 = Saturday.
 * A resource with no rows at all has no hours defined: it is bookable
 * at any time and nothing is checked.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE appointment_working_hours (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                resource_id INT UNSIGNED NOT NULL,
                weekday TINYINT UNSIGNED NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                KEY idx_aphours_resource (resource_id, weekday),
                CONSTRAINT fk_aphours_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_aphours_resource FOREIGN KEY (resource_id) REFERENCES appointment_resources (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS appointment_working_hours');
    }
};
