<?php

declare(strict_types=1);

namespace Modules\Wallet\Gateways;

/**
 * One interface, many drivers — matches the pattern already
 * established for Mail (log/php_mail/smtp). ZarinPal is the first
 * real implementation; Mellat and Saman Kish (both bank PSPs with a
 * broadly similar request → redirect → callback → verify shape) are
 * meant to implement this same interface and register in
 * GatewayRegistry — nothing else in the Wallet module should need to
 * change when they're added.
 */
interface PaymentGatewayInterface
{
    /** Short, stable key — e.g. 'zarinpal'. Stored on wallet_transactions.gateway_key. */
    public function key(): string;

    /** Human-readable name for the top-up form's gateway picker. */
    public function label(): string;

    /**
     * Starts a payment. $amount is a positive integer in the
     * gateway's own smallest whole unit (for ZarinPal: Rials — see
     * ZarinPalGateway's docblock on the Toman/Rial distinction).
     * Returns a redirect URL to send the browser to, plus the
     * gateway's own reference to store for verify() later.
     */
    public function initiate(int $amount, string $callbackUrl, string $description, array $metadata = []): GatewayInitiateResult;

    /**
     * Confirms a payment after the gateway redirects the browser
     * back. $callbackParams is whatever that gateway appends to the
     * callback URL (varies per gateway — ZarinPal uses Authority/Status).
     * $storedReference is what initiate() returned, fetched back from
     * our own wallet_transactions row — never trust the callback's
     * own params alone for the reference, only for routing.
     */
    public function verify(array $callbackParams, int $amount, string $storedReference): GatewayVerifyResult;
}
