<?php

declare(strict_types=1);

namespace Tests\Lite\Fixtures;

use Modules\MarketingBale\Billing\BillableMessageSink;
use Modules\MarketingBale\Safir\SafirHttpResponse;
use Modules\MarketingBale\Safir\SafirTransport;

/**
 * Stands in for the network in every Bale test: nothing ever reaches
 * safir.bale.ai. Records each request (url, headers, decoded body) and
 * answers with $responder($body) — default: accepted with a fake id.
 */
final class FakeSafirTransport implements SafirTransport
{
    /** @var array<int, array{url: string, headers: array<int, string>, raw: string, body: array}> */
    public array $requests = [];
    public int $seq = 0;

    /** @var \Closure(array): SafirHttpResponse */
    public \Closure $responder;

    public function __construct(?\Closure $responder = null)
    {
        $this->responder = $responder ?? fn (array $body) => self::accepted('lite-' . (++$this->seq));
    }

    public function post(string $url, array $headers, string $body): SafirHttpResponse
    {
        $decoded = json_decode($body, true) ?? [];
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'raw' => $body, 'body' => $decoded];

        return ($this->responder)($decoded);
    }

    public function last(): ?array
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public static function accepted(string $messageId): SafirHttpResponse
    {
        return new SafirHttpResponse(200, (string) json_encode(['message_id' => $messageId, 'error_data' => null]));
    }

    public static function error(int $code, int $status = 200, string $phone = '989000000000'): SafirHttpResponse
    {
        return new SafirHttpResponse($status, (string) json_encode(['message_id' => null, 'error_data' => [['phone_number' => $phone, 'code' => $code, 'description' => 'desc for ' . $phone]]]));
    }
}

/** Records billable-message events in memory (the no-op default is NullBillableMessageSink). */
final class RecordingBillableMessageSink implements BillableMessageSink
{
    /** @var array<string, array> keyed by event_key — a repeated key is counted in $duplicates */
    public array $events = [];
    public int $duplicates = 0;
    public bool $throw = false;

    public function record(array $event): void
    {
        if ($this->throw) {
            throw new \RuntimeException('sink down');
        }
        if (isset($this->events[$event['event_key']])) {
            $this->duplicates++;
        }
        $this->events[$event['event_key']] = $event;
    }
}
