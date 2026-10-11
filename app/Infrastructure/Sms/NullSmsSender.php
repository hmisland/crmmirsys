<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * Used when no provider can send (no platform key, or no provider for
 * the organization's region): every send fails honestly with $reason,
 * nothing pretends to work.
 */
final class NullSmsSender implements SmsSenderInterface
{
    public function __construct(private readonly string $reason = 'No SMS provider is configured.')
    {
    }

    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(array $receptors, string $message, ?string $sender = null): SmsResult
    {
        return SmsResult::failure($this->reason);
    }

    public function sendTemplate(string $receptor, string $template, array $tokens): SmsResult
    {
        return SmsResult::failure($this->reason);
    }

    public function accountInfo(): array
    {
        return ['credit' => null, 'expires_at' => null, 'error' => $this->reason];
    }
}
