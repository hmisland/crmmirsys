<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Safir;

/**
 * Raw result of one HTTP request.
 *
 * status null = no HTTP response at all. requestSent tells whether the
 * request could have reached Safir: false only when the connection
 * itself failed (DNS, refused, TLS handshake) — then nothing was sent
 * and a retry cannot duplicate a message.
 */
final class SafirHttpResponse
{
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $body,
        public readonly bool $requestSent = true,
        public readonly int $transportError = 0,
    ) {
    }

    public static function notSent(int $transportError): self
    {
        return new self(null, null, false, $transportError);
    }

    public static function lost(int $transportError): self
    {
        return new self(null, null, true, $transportError);
    }
}
