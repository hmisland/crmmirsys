<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * An organization's own provider account (any provider) — sends exactly
 * like the inner sender, but is logged as "<provider>-own" so
 * platform-paid and own-account sends can be told apart.
 */
final class OrganizationAccountSender implements SmsSenderInterface
{
    public function __construct(private readonly SmsSenderInterface $inner)
    {
    }

    public function name(): string
    {
        return $this->inner->name() . '-own';
    }

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured();
    }

    public function send(array $receptors, string $message, ?string $sender = null): SmsResult
    {
        return $this->inner->send($receptors, $message, $sender);
    }

    public function sendTemplate(string $receptor, string $template, array $tokens): SmsResult
    {
        return $this->inner->sendTemplate($receptor, $template, $tokens);
    }

    public function accountInfo(): array
    {
        return $this->inner->accountInfo();
    }
}
