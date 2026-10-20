<?php

declare(strict_types=1);

namespace Modules\CustomerPortal\Services;

/** Hands a sign-in code to the customer. Never logs or returns the code. */
interface OtpDelivery
{
    public const EMAIL = 'email';
    public const SMS = 'sms';

    /** Whether email codes can really be delivered (not into a log file). */
    public function emailAvailable(): bool;

    /** @param array{id: int, name: string} $organization */
    public function send(string $channel, string $destination, string $code, array $organization, string $locale): bool;
}
