<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Not listed as its own item in any Phase 0 phase, but "Email
 * verification" and "Password reset" (both explicitly in Phase 2's
 * scope) can't work without SOME way to deliver a link. Kept as a
 * small interface, exactly like PaymentGatewayInterface in Phase 0
 * §18/§26, so the concrete transport (log file today; real SMTP/API
 * provider later) is swappable via config without touching AuthService.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $body): void;
}
