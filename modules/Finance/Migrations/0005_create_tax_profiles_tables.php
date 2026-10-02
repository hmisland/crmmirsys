<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE tax_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NULL,
                CONSTRAINT fk_tax_profiles_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            'CREATE TABLE tax_profile_rules (
                tax_profile_id INT UNSIGNED NOT NULL,
                tax_rule_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (tax_profile_id, tax_rule_id),
                CONSTRAINT fk_tax_profile_rules_profile FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles (id) ON DELETE CASCADE,
                CONSTRAINT fk_tax_profile_rules_rule FOREIGN KEY (tax_rule_id) REFERENCES tax_rules (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec("INSERT INTO tax_profiles (organization_id, name, description) VALUES (NULL, 'Standard VAT (Iran)', 'Platform template — the current standard Iranian VAT rate.')");
        $profileId = $pdo->lastInsertId();

        $ruleId = $pdo->query("SELECT id FROM tax_rules WHERE name = 'Standard VAT (Iran) — 10%' LIMIT 1")->fetchColumn();

        if ($ruleId !== false) {
            $stmt = $pdo->prepare('INSERT INTO tax_profile_rules (tax_profile_id, tax_rule_id) VALUES (:pid, :rid)');
            $stmt->execute(['pid' => $profileId, 'rid' => $ruleId]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tax_profile_rules');
        $pdo->exec('DROP TABLE IF EXISTS tax_profiles');
    }
};
