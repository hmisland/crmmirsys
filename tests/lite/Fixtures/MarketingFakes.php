<?php

declare(strict_types=1);

namespace Tests\Lite\Fixtures;

use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Sms\SmsDeliveryReportingInterface;
use App\Infrastructure\Sms\SmsResult;
use App\Infrastructure\Sms\SmsSenderInterface;

/**
 * Test doubles for the Marketing suites. No real provider is ever
 * called: SMS goes to FakeSmsSender, email adapters get FakeHttpClient.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];

    /** @param \Closure(string, string, array, ?string): HttpResponse $responder */
    public function __construct(public \Closure $responder)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return ($this->responder)($method, $url, $headers, $body);
    }

    public static function json(int $status, array $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body), $headers);
    }
}

final class FakeSmsSender implements SmsSenderInterface, SmsDeliveryReportingInterface
{
    /** @var list<array{receptor: string, message: string, local_id: ?int}> */
    public array $sent = [];
    /** @var array<int, true> localids already accepted (provider-side dedupe, like Kavenegar) */
    public array $seenLocalIds = [];
    /** @var list<SmsResult> results to return before the default success */
    public array $script = [];
    /** @var array<string, array{state: string, code: int, text: string}> */
    public array $statuses = [];
    public int $nextId = 5000;

    public function name(): string
    {
        return 'fakesms';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(array $receptors, string $message, ?string $sender = null): SmsResult
    {
        foreach ($receptors as $r) {
            $this->sent[] = ['receptor' => $r, 'message' => $message, 'local_id' => null];
        }

        return new SmsResult(true, null, 200, array_map(fn () => (string) $this->nextId++, $receptors));
    }

    public function sendTemplate(string $receptor, string $template, array $tokens): SmsResult
    {
        return SmsResult::failure('not used');
    }

    public function accountInfo(): array
    {
        return ['credit' => 1000, 'expires_at' => null, 'error' => null];
    }

    /** Every sendWithLocalId() call, including ones the provider deduplicates. */
    public int $sendCalls = 0;

    public function sendWithLocalId(string $receptor, string $message, int $localId, ?string $sender = null): SmsResult
    {
        $this->sendCalls++;
        if ($this->script !== []) {
            $result = array_shift($this->script);
            if (!$result->success) {
                return $result;
            }
        }
        if (isset($this->seenLocalIds[$localId])) {
            // Same as Kavenegar: a repeated localid returns the earlier record, nothing is sent.
            return new SmsResult(true, null, 200, [(string) $this->seenLocalIds[$localId]]);
        }

        $id = $this->nextId++;
        $this->seenLocalIds[$localId] = $id;
        $this->sent[] = ['receptor' => $receptor, 'message' => $message, 'local_id' => $localId];

        return new SmsResult(true, null, 200, [(string) $id]);
    }

    public function deliveryStatuses(array $messageIds): array
    {
        return array_intersect_key($this->statuses, array_flip(array_map('strval', $messageIds)));
    }

    /** false = behave like a provider that can't be asked (lookup returns null). */
    public bool $lookupAvailable = true;
    /** @var list<int> localids looked up */
    public array $lookups = [];

    public function findByLocalId(int $localId): ?array
    {
        $this->lookups[] = $localId;
        if (!$this->lookupAvailable) {
            return null;
        }

        return isset($this->seenLocalIds[$localId])
            ? ['found' => true, 'message_id' => (string) $this->seenLocalIds[$localId], 'state' => 'sent']
            : ['found' => false, 'message_id' => null, 'state' => 'unknown'];
    }
}
