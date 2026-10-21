<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Infrastructure\Config\Config;

/**
 * Whether the CRM's mailer can really deliver — the rule background
 * senders use before claiming work (same rule as the Task Manager's
 * TaskReminderService::mailConfigured()): SMTP with host, user, password
 * and a sender; PHP mail() with a sender; the "log" driver (files in
 * storage/mail) only outside production.
 */
final class MailReadiness
{
    public static function isConfigured(): bool
    {
        $from = (string) Config::get('mail.from_address', '');
        $sender = filter_var($from, FILTER_VALIDATE_EMAIL) !== false && !str_ends_with(strtolower($from), '@example.com');

        return match ((string) Config::get('mail.driver', 'log')) {
            'smtp' => $sender && (string) Config::get('mail.smtp.host', '') !== '' && (string) Config::get('mail.smtp.username', '') !== ''
                && (string) Config::get('mail.smtp.password', '') !== '',
            'php_mail' => $sender,
            'log' => Config::get('app.env', 'production') !== 'production',
            default => false,
        };
    }
}
