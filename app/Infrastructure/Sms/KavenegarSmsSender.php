<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * Kavenegar REST API (https://kavenegar.com/rest.html, checked against
 * the official docs):
 *   https://api.kavenegar.com/v1/{API-KEY}/{scope}/{method}.json
 *   - sms/send.json      receptor (comma-separated), message, sender?
 *   - verify/lookup.json receptor, template, token, token2?, token3?
 *   - account/info.json  → entries.remaincredit (Rials), expiredate
 * Every response is {"return": {"status", "message"}, "entries": ...};
 * return.status 200 = accepted, anything else is an error code (403
 * bad key, 411 bad receptor, 412 bad sender, 418 no credit, 424 template
 * not found, ...) with a Persian message, which is passed through.
 */
final class KavenegarSmsSender implements SmsSenderInterface, SmsDeliveryReportingInterface
{
    private const BASE_URL = 'https://api.kavenegar.com/v1/';
    private const MAX_RECEPTORS_PER_CALL = 200; // provider limit per send request
    private const MAX_STATUS_IDS_PER_CALL = 500; // sms/status.json limit

    /**
     * sms/status.json codes (official table): 1 queued, 2 scheduled,
     * 4/5 sent to the carrier, 6 failed, 10 delivered, 11 undelivered,
     * 13 cancelled (refunded), 14 blocked — the recipient opted out of
     * promotional lines (refunded), 100 unknown/expired id (status is
     * kept for 48 hours only).
     */
    private const STATUS_STATES = [
        1 => 'queued', 2 => 'queued', 4 => 'sent', 5 => 'sent', 6 => 'failed',
        10 => 'delivered', 11 => 'undelivered', 13 => 'cancelled', 14 => 'blocked', 100 => 'unknown',
    ];

    /**
     * Kavenegar requires a sender line, and domestic and international
     * messages use different lines: Iranian numbers (09…) go out on
     * $defaultSender, international ones (00…) on $internationalSender.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultSender = '',
        private readonly string $internationalSender = '',
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function name(): string
    {
        return 'kavenegar';
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function send(array $receptors, string $message, ?string $sender = null): SmsResult
    {
        if (!$this->isConfigured()) {
            return SmsResult::failure('Kavenegar API key is not configured.');
        }

        $messageIds = [];
        $cost = 0;

        // Domestic and international numbers need different sender lines.
        $groups = ['domestic' => [], 'international' => []];
        foreach (array_values($receptors) as $receptor) {
            $groups[str_starts_with($receptor, '00') ? 'international' : 'domestic'][] = $receptor;
        }

        foreach ($groups as $group => $numbers) {
            if ($numbers === []) {
                continue;
            }

            $line = trim((string) ($group === 'international' ? $this->internationalSender : ($sender ?? $this->defaultSender)));
            if ($line === '') {
                return SmsResult::failure($group === 'international'
                    ? 'No international sender line is set for Kavenegar.'
                    : 'No sender line is set for Kavenegar.', 412);
            }

            foreach (array_chunk($numbers, self::MAX_RECEPTORS_PER_CALL) as $chunk) {
                $result = $this->call('sms/send.json', ['receptor' => implode(',', $chunk), 'message' => $message, 'sender' => $line]);
                if (!$result['ok']) {
                    return SmsResult::failure($result['error'], $result['status']);
                }

                foreach ((array) $result['entries'] as $entry) {
                    $messageIds[] = (string) ($entry['messageid'] ?? '');
                    $cost += (int) ($entry['cost'] ?? 0);
                }
            }
        }

        return new SmsResult(true, null, 200, array_values(array_filter($messageIds)), $cost);
    }

    public function sendTemplate(string $receptor, string $template, array $tokens): SmsResult
    {
        if (!$this->isConfigured()) {
            return SmsResult::failure('Kavenegar API key is not configured.');
        }

        $tokens = array_values($tokens);
        $params = ['receptor' => $receptor, 'template' => $template, 'token' => (string) ($tokens[0] ?? '')];
        if (isset($tokens[1])) {
            $params['token2'] = (string) $tokens[1];
        }
        if (isset($tokens[2])) {
            $params['token3'] = (string) $tokens[2];
        }

        $result = $this->call('verify/lookup.json', $params);
        if (!$result['ok']) {
            return SmsResult::failure($result['error'], $result['status']);
        }

        $entries = (array) $result['entries'];
        $first = $entries[0] ?? $entries;

        return new SmsResult(true, null, 200, array_filter([(string) ($first['messageid'] ?? '')]), (int) ($first['cost'] ?? 0));
    }

    public function accountInfo(): array
    {
        if (!$this->isConfigured()) {
            return ['credit' => null, 'expires_at' => null, 'error' => 'Kavenegar API key is not configured.'];
        }

        // GET-only on Kavenegar's side (POST answers "متد نامشخص است").
        $result = $this->call('account/info.json', [], 'GET');
        if (!$result['ok']) {
            return ['credit' => null, 'expires_at' => null, 'error' => $result['error']];
        }

        $entries = (array) $result['entries'];

        return [
            'credit' => isset($entries['remaincredit']) ? (int) $entries['remaincredit'] : null,
            'expires_at' => isset($entries['expiredate']) ? (int) $entries['expiredate'] : null,
            'error' => null,
        ];
    }

    public function sendWithLocalId(string $receptor, string $message, int $localId, ?string $sender = null): SmsResult
    {
        if (!$this->isConfigured()) {
            return SmsResult::failure('Kavenegar API key is not configured.');
        }

        $international = str_starts_with($receptor, '00');
        $line = trim((string) ($international ? $this->internationalSender : ($sender ?? $this->defaultSender)));
        if ($line === '') {
            return SmsResult::failure($international ? 'No international sender line is set for Kavenegar.' : 'No sender line is set for Kavenegar.', 412);
        }

        // localid: Kavenegar never sends twice for the same id — a repeat
        // returns the earlier record, so a retried job cannot double-send.
        $result = $this->call('sms/send.json', ['receptor' => $receptor, 'message' => $message, 'sender' => $line, 'localid' => (string) $localId]);
        if (!$result['ok']) {
            return SmsResult::failure($result['error'], $result['status']);
        }

        $entries = (array) $result['entries'];
        $first = $entries[0] ?? $entries;

        return new SmsResult(true, null, 200, array_values(array_filter([(string) ($first['messageid'] ?? '')])), (int) ($first['cost'] ?? 0));
    }

    public function deliveryStatuses(array $messageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $messageIds), fn ($id) => ctype_digit($id))));
        if ($ids === [] || !$this->isConfigured()) {
            return [];
        }

        $statuses = [];
        foreach (array_chunk($ids, self::MAX_STATUS_IDS_PER_CALL) as $chunk) {
            $result = $this->call('sms/status.json', ['messageid' => implode(',', $chunk)]);
            if ($result['ok']) {
                $statuses += self::parseStatusEntries((array) $result['entries']);
            }
        }

        return $statuses;
    }

    /**
     * sms/statuslocalmessageid.json?localid=… (kept by Kavenegar for 12
     * hours). Status 100 = no message with that id → not found.
     */
    public function findByLocalId(int $localId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $result = $this->call('sms/statuslocalmessageid.json', ['localid' => (string) $localId]);
        if (!$result['ok']) {
            return null;
        }

        return self::parseLocalIdEntries((array) $result['entries']);
    }

    /** @return array{found: bool, message_id: ?string, state: string} */
    public static function parseLocalIdEntries(array $entries): array
    {
        $first = $entries[0] ?? $entries;
        $code = (int) ($first['status'] ?? 100);
        $messageId = (string) ($first['messageid'] ?? '');
        if ($code === 100 || $messageId === '' || $messageId === '0') {
            return ['found' => false, 'message_id' => null, 'state' => 'unknown'];
        }

        return ['found' => true, 'message_id' => $messageId, 'state' => self::STATUS_STATES[$code] ?? 'unknown'];
    }

    /**
     * @param array<int, array{messageid?: mixed, status?: mixed, statustext?: mixed}> $entries
     * @return array<string, array{state: string, code: int, text: string}>
     */
    public static function parseStatusEntries(array $entries): array
    {
        $statuses = [];
        foreach ($entries as $entry) {
            $id = (string) ($entry['messageid'] ?? '');
            if ($id === '') {
                continue;
            }
            $code = (int) ($entry['status'] ?? 0);
            $statuses[$id] = ['state' => self::STATUS_STATES[$code] ?? 'unknown', 'code' => $code, 'text' => mb_substr((string) ($entry['statustext'] ?? ''), 0, 100)];
        }

        return $statuses;
    }

    /** @return array{ok: bool, status: ?int, error: string, entries: mixed} */
    private function call(string $method, array $params, string $httpMethod = 'POST'): array
    {
        $url = self::BASE_URL . rawurlencode(trim($this->apiKey)) . '/' . $method;
        $isPost = $httpMethod === 'POST';
        if (!$isPost && $params !== []) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        if ($isPost) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params)]);
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => null, 'error' => 'Could not reach Kavenegar: ' . $curlError, 'entries' => null];
        }

        $decoded = json_decode((string) $body, true);
        $status = isset($decoded['return']['status']) ? (int) $decoded['return']['status'] : null;

        if ($status !== 200) {
            $message = (string) ($decoded['return']['message'] ?? 'Unexpected response from Kavenegar.');

            return ['ok' => false, 'status' => $status, 'error' => $message, 'entries' => null];
        }

        return ['ok' => true, 'status' => 200, 'error' => '', 'entries' => $decoded['entries'] ?? []];
    }
}
