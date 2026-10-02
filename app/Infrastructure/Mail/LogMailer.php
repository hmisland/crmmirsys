<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Default driver. Writes each "sent" email to storage/mail/ as a plain
 * text file instead of actually sending it — safe for local
 * development and for a fresh production deploy before mail is
 * configured (never silently drops mail into a real inbox by
 * accident). Switch MAIL_DRIVER=php_mail in .env for a real transport
 * once ready — see PhpMailMailer.
 */
final class LogMailer implements MailerInterface
{
    public function __construct(private readonly string $mailDirectory)
    {
        if (!is_dir($this->mailDirectory)) {
            mkdir($this->mailDirectory, 0755, true);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        $safeTo = preg_replace('/[^a-z0-9@._-]+/i', '-', $to);
        $file = sprintf('%s/%s_%s.txt', $this->mailDirectory, date('Y-m-d_His'), $safeTo);

        file_put_contents(
            $file,
            "To: {$to}\r\nSubject: {$subject}\r\nDate: " . date(DATE_RFC2822) . "\r\n\r\n{$body}\r\n"
        );
    }
}
