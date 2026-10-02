<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE tax_jurisdictions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                country_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                CONSTRAINT fk_tax_jurisdictions_country FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $iranId = $pdo->query("SELECT id FROM countries WHERE iso_code = 'IRN'")->fetchColumn();
        if ($iranId !== false) {
            $stmt = $pdo->prepare('INSERT INTO tax_jurisdictions (country_id, name) VALUES (:cid, :name)');
            $stmt->execute(['cid' => $iranId, 'name' => 'Iran — standard']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tax_jurisdictions');
    }
};
