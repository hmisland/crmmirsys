<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Platform-wide, not tenant-scoped (like `currencies`) — the list of
 * licensed Iranian banks doesn't vary per organization. Seeded with a
 * solid starter list of the major, well-established banks; this is
 * NOT guaranteed to be exhaustive or perfectly current (bank licenses
 * do occasionally change), so Super Admin can add any missing one
 * from `/admin/wallet/settings` — same "best-effort + extensible"
 * approach already used for Iran's provinces list (see
 * IranProvinces::PROVINCE_CITIES's own docblock for the same
 * reasoning).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE banks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $pdo->prepare('INSERT INTO banks (name) VALUES (:name)');
        foreach ([
            'بانک ملی ایران', 'بانک ملت', 'بانک صادرات ایران', 'بانک تجارت', 'بانک سپه',
            'بانک کشاورزی', 'بانک مسکن', 'بانک رفاه کارگران', 'بانک پست بانک ایران',
            'بانک پارسیان', 'بانک پاسارگاد', 'بانک سامان', 'بانک سینا', 'بانک کارآفرین',
            'بانک اقتصاد نوین', 'بانک توسعه تعاون', 'بانک آینده', 'بانک شهر', 'بانک دی',
            'بانک انصار', 'بانک گردشگری', 'بانک ایران زمین', 'بانک مهر ایران', 'بانک رسالت',
            'بانک خاورمیانه', 'بانک ملل (تات سابق)', 'بانک سرمایه', 'موسسه اعتباری ملل',
            'بانک صنعت و معدن', 'بانک توسعه صادرات ایران',
        ] as $name) {
            $stmt->execute(['name' => $name]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS banks');
    }
};
