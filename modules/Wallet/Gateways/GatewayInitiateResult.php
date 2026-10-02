<?php

declare(strict_types=1);

namespace Modules\Wallet\Gateways;

final class GatewayInitiateResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $redirectUrl,
        public readonly ?string $reference,
        public readonly ?string $errorMessage,
        public readonly string $rawResponse,
    ) {
    }
}
