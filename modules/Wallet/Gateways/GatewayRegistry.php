<?php

declare(strict_types=1);

namespace Modules\Wallet\Gateways;

final class GatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function get(string $key): ?PaymentGatewayInterface
    {
        return $this->gateways[$key] ?? null;
    }

    /** @return PaymentGatewayInterface[] */
    public function all(): array
    {
        return array_values($this->gateways);
    }
}
