<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Services;

use App\Application\Services\TimelineService;

/**
 * The unified client timeline is a READ-SIDE merge, not a second
 * activity engine: it reads the Core timeline_events of the contact and
 * of the record (both written by the existing TimelineService), plus
 * invoice/payment facts straight from the Finance module's own services
 * (only what the caller may see there). Nothing is copied or stored.
 *
 * Each item: at, type, description, icon, tone, href (nullable),
 * amount/currency (finance only). Templates translate `type` through
 * client_records.event.* and fall back to the stored description.
 */
final class ClientTimelineService
{
    /** event type prefix => [icon, tone] */
    private const STYLE = [
        'client_record' => ['clipboard', 'brand'],
        'session_completed' => ['check-circle', 'success'],
        'session_recorded' => ['activity', 'success'],
        'session_scheduled' => ['calendar', 'info'],
        'session_cancelled' => ['x-circle', 'danger'],
        'session_no_show' => ['ban', 'warning'],
        'session' => ['activity', 'brand'],
        'service' => ['package', 'brand'],
        'media' => ['image', 'info'],
        'document' => ['file-text', 'info'],
        'attachment' => ['paperclip', 'info'],
        'note' => ['message', 'neutral'],
        'follow_up_completed' => ['calendar-check', 'success'],
        'follow_up' => ['calendar-check', 'warning'],
        'invoice' => ['receipt', 'info'],
        'payment' => ['credit-card', 'success'],
        'created' => ['user-plus', 'brand'],
        'tag' => ['tag', 'neutral'],
    ];

    public function __construct(
        private readonly TimelineService $timeline,
        private readonly ClientIntegrations $integrations,
        private readonly ClientRecordService $records,
    ) {
    }

    /**
     * @param array|null $invoices the contact's invoices if the caller already loaded them (avoids a second query)
     * @param array<int, array> $paymentsByInvoice
     */
    public function build(array $actingUser, array $record, ?array $invoices = null, array $paymentsByInvoice = [], int $limit = 100): array
    {
        $items = [];

        foreach ($this->timeline->forSubject('contact', (int) $record['contact_id']) as $event) {
            $items[] = $this->fromEvent($event, false);
        }

        // Media/document activity is itself sensitive (it reveals that,
        // e.g., before/after photos exist) — same gate as the files.
        $canSensitive = $this->records->canViewSensitive($actingUser, $record);

        foreach ($this->timeline->forSubject('client_record', (int) $record['id']) as $event) {
            if ($event['type'] === 'client_record_created') {
                continue; // the same moment is already on the contact's timeline
            }
            if (!$canSensitive && preg_match('/^(media|document)_/', (string) $event['type']) === 1) {
                continue;
            }
            $items[] = $this->fromEvent($event, true);
        }

        $invoices ??= $this->integrations->invoicesForContact($actingUser, (int) $record['contact_id']);
        if ($paymentsByInvoice === [] && $invoices !== []) {
            $paymentsByInvoice = $this->integrations->paymentsForInvoices($actingUser, $invoices);
        }

        foreach ($invoices as $invoice) {
            $isIssued = $invoice['status'] === 'issued';
            $items[] = [
                'at' => $isIssued && !empty($invoice['finalized_at']) ? $invoice['finalized_at'] : $invoice['created_at'],
                'type' => $isIssued ? 'invoice_issued' : ($invoice['status'] === 'cancelled' ? 'invoice_cancelled' : 'invoice_created'),
                'description' => (string) ($invoice['invoice_number'] ?? ('#' . $invoice['id'])),
                'detail' => (string) ($invoice['invoice_number'] ?? ('#' . $invoice['id'])),
                'href' => '/finance/invoices/' . (int) $invoice['id'],
                'amount' => $invoice['grand_total'],
                'currency' => $invoice['currency_code'] ?? '',
            ] + $this->style('invoice');

            foreach ($paymentsByInvoice[(int) $invoice['id']] ?? [] as $payment) {
                $items[] = [
                    'at' => $payment['payment_date'] . ' 12:00:00',
                    'type' => 'payment_received',
                    'description' => (string) ($invoice['invoice_number'] ?? ('#' . $invoice['id'])),
                'detail' => (string) ($invoice['invoice_number'] ?? ('#' . $invoice['id'])),
                    'href' => '/finance/invoices/' . (int) $invoice['id'],
                    'amount' => $payment['amount'],
                    'currency' => $invoice['currency_code'] ?? '',
                ] + $this->style('payment');
            }
        }

        usort($items, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, $limit);
    }

    /**
     * This module stores its own service/session events as
     * "Label: <user-entered title>" (readable as plain audit text). The
     * label part is re-translated from the type at display time, so only
     * the title after ": " is kept as `detail`.
     */
    private function fromEvent(array $event, bool $fromRecord): array
    {
        $type = (string) $event['type'];
        $description = (string) $event['description'];
        $detail = null;

        if ($fromRecord && preg_match('/^(service|session)_/', $type) === 1 && str_contains($description, ': ')) {
            $detail = substr($description, strpos($description, ': ') + 2);
        }

        return [
            'at' => $event['created_at'],
            'type' => $type,
            'description' => $description,
            'detail' => $detail,
            'href' => null,
            'amount' => null,
            'currency' => null,
        ] + $this->style($type);
    }

    /** @return array{icon: string, tone: string} */
    private function style(string $type): array
    {
        $best = null;
        foreach (self::STYLE as $prefix => $style) {
            if (str_starts_with($type, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        [$icon, $tone] = $best !== null ? self::STYLE[$best] : ['history', 'neutral'];

        return ['icon' => $icon, 'tone' => $tone];
    }
}
