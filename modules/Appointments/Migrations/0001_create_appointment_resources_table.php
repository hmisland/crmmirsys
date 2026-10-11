<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Anything that can be booked: a person, a place (room, bay, desk,
 * lane...) or a piece of equipment. A `person` resource may be linked
 * to a user so their appointments are also theirs (RBAC OWN scope).
 *
 * `capacity` = how many appointments may overlap on this resource at
 * the same time (1 for a single person/place, more for a shared space).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE appointment_resources (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                kind ENUM('person', 'place', 'equipment') NOT NULL DEFAULT 'person',
                user_id INT UNSIGNED NULL,
                capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                description TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_apres_org_active (organization_id, is_active, sort_order),
                KEY idx_apres_user (user_id),
                CONSTRAINT fk_apres_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_apres_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS appointment_resources');
    }
};
