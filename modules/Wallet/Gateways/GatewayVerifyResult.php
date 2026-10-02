<?php

declare(strict_types=1);

namespace Modules\Wallet\Gateways;

final class GatewayVerifyResult
{
    public function __construct(
        public readonly bool $success,
        /** True when the gateway itself reports "already verified" — still a success, just not a fresh one. */
        public readonly bool $alreadyVerified,
        public readonly ?string $refId,
        public readonly ?string $cardMasked,
        public readonly ?string $errorMessage,
        public readonly string $rawResponse,
    ) {
    }
}
