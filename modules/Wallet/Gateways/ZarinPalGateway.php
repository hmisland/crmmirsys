<?php

declare(strict_types=1);

namespace Modules\Wallet\Gateways;

/**
 * ZarinPal's v4 REST API (confirmed against https://www.zarinpal.com/docs
 * at build time — request/verify JSON shapes, response codes, and the
 * Toman→Rial conversion below are all taken from there, not guessed).
 *
 * IMPORTANT — amount unit: ZarinPal's v4 API expects `amount` in
 * RIALS. Iranian businesses commonly think and quote prices in TOMAN
 * (1 Toman = 10 Rial). To avoid exactly that ambiguity, this Wallet
 * module's top-up flow requires the wallet's currency to be IRR
 * (Rial) when ZarinPal is the selected gateway — the amount the user
 * enters is passed straight through as Rials, no silent ×10
 * conversion happens here. If a future org wants to think in Toman,
 * that conversion belongs in the UI layer (label the input "Toman"
 * and ×10 before calling this class), not hidden inside the gateway.
 *
 * Response codes that matter: 100 = success/first-time verified;
 * 101 = "already verified" on a repeat verify call — ZarinPal's own
 * idempotency signal, treated here as a success, not an error (the
 * unique DB constraint on wallet_transactions.provider_reference is
 * the primary idempotency guard; this is the gateway's own backstop).
 * Anything else is a failure; ZarinPal's `message` is passed through.
 */
final class ZarinPalGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox,
    ) {
    }

    public function key(): string
    {
        return 'zarinpal';
    }

    public function label(): string
    {
        return 'زرین‌پال (ZarinPal)';
    }

    public function initiate(int $amount, string $callbackUrl, string $description, array $metadata = []): GatewayInitiateResult
    {
        $host = $this->sandbox ? 'sandbox.zarinpal.com' : 'payment.zarinpal.com';

        $payload = [
            'merchant_id' => $this->merchantId,
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'description' => $description,
        ];
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        [$response, $error] = $this->post("https://{$host}/pg/v4/payment/request.json", $payload);

        if ($error !== null) {
            return new GatewayInitiateResult(false, null, null, $error, '');
        }

        $decoded = json_decode($response, true);
        $code = $decoded['data']['code'] ?? null;

        if ($code !== 100 || empty($decoded['data']['authority'])) {
            $message = $decoded['errors']['message'] ?? $decoded['data']['message'] ?? 'ZarinPal request failed.';

            return new GatewayInitiateResult(false, null, null, $message, $response);
        }

        $authority = $decoded['data']['authority'];
        $startPayHost = $this->sandbox ? 'sandbox.zarinpal.com' : 'www.zarinpal.com';

        return new GatewayInitiateResult(true, "https://{$startPayHost}/pg/StartPay/{$authority}", $authority, null, $response);
    }

    public function verify(array $callbackParams, int $amount, string $storedReference): GatewayVerifyResult
    {
        if (($callbackParams['Status'] ?? '') !== 'OK') {
            return new GatewayVerifyResult(false, false, null, null, 'Payment was cancelled or failed at the gateway.', '');
        }

        $host = $this->sandbox ? 'sandbox.zarinpal.com' : 'payment.zarinpal.com';

        [$response, $error] = $this->post("https://{$host}/pg/v4/payment/verify.json", [
            'merchant_id' => $this->merchantId,
            'amount' => $amount,
            'authority' => $storedReference,
        ]);

        if ($error !== null) {
            return new GatewayVerifyResult(false, false, null, null, $error, '');
        }

        $decoded = json_decode($response, true);
        $code = $decoded['data']['code'] ?? null;

        if ($code !== 100 && $code !== 101) {
            $message = $decoded['errors']['message'] ?? $decoded['data']['message'] ?? 'ZarinPal verification failed.';

            return new GatewayVerifyResult(false, false, null, null, $message, $response);
        }

        return new GatewayVerifyResult(
            true,
            $code === 101,
            isset($decoded['data']['ref_id']) ? (string) $decoded['data']['ref_id'] : null,
            $decoded['data']['card_pan'] ?? null,
            null,
            $response
        );
    }

    /** @return array{0: string, 1: ?string} [responseBody, errorMessageOrNull] */
    private function post(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['', 'Could not reach ZarinPal: ' . $curlError];
        }

        return [$response, null];
    }
}
