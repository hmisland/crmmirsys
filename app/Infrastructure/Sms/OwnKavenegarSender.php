<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * An organization's own Kavenegar account — same driver as the platform,
 * logged under a different provider name so platform-paid and own-key
 * sends can be told apart.
 */
final class OwnKavenegarSender implements SmsSenderInterface
{
    private KavenegarSmsSender $inner;

    public function __construct(string $apiKey, string $sender)
    {
        $this->inner = new KavenegarSmsSender($apiKey, $sender);
    }

    public function name(): string
    {
        return 'kavenegar-own';
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
