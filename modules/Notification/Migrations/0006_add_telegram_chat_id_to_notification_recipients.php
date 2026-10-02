<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Groundwork only, same as `phone_number` was for SMS before a real
 * provider existed — no TelegramGateway is implemented yet. Adding
 * the column now means the Profile page can collect it today, and a
 * future PaymentGatewayInterface-style TelegramGateway just needs
 * this column to already be there when it's built.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE notification_recipients ADD COLUMN telegram_chat_id VARCHAR(100) NULL AFTER bale_chat_id');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE notification_recipients DROP COLUMN telegram_chat_id');
    }
};
