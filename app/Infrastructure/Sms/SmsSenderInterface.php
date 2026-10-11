<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * One SMS provider. The app talks to SmsService, never to a provider
 * directly — swapping Kavenegar for another provider means writing one
 * class implementing this interface and binding it in Application.
 */
interface SmsSenderInterface
{
    /** Short provider name for logs, e.g. 'kavenegar'. */
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * A free-text message to one or more numbers (already normalized).
     *
     * @param string[] $receptors
     */
    public function send(array $receptors, string $message, ?string $sender = null): SmsResult;

    /**
     * A message from a template defined in the provider's panel
     * (Kavenegar "verify/lookup") — used for OTP / verification codes,
     * which providers deliver on a priority route.
     *
     * @param string[] $tokens up to 3 values, filling %token, %token2, %token3
     */
    public function sendTemplate(string $receptor, string $template, array $tokens): SmsResult;

    /** @return array{credit: ?int, expires_at: ?int, error: ?string} credit in Rials */
    public function accountInfo(): array;
}
