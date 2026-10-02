<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Uses PHP's built-in mail() (i.e. the host's configured sendmail),
 * which is typically already available on shared cPanel hosting with
 * zero extra setup — no SMTP host/port/credentials needed. Deliverability
 * is host-dependent (some hosts' shared IPs land in spam); if that
 * becomes a problem later, a proper SMTP/API-based MailerInterface
 * implementation can be swapped in via MAIL_DRIVER without touching
 * AuthService or anything that calls MailerInterface::send().
 */
final class PhpMailMailer implements MailerInterface
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $headers = implode("\r\n", [
            'From: ' . $this->fromName . ' <' . $this->fromAddress . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        // Subject with non-ASCII characters must be MIME-encoded or
        // most clients render it as garbage.
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        mail($to, $encodedSubject, $body, $headers);
    }
}
