<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Billing;

/**
 * Seam for FUTURE per-message billing — nothing is charged in this phase.
 *
 * BaleChannel calls record() once for each final Safir outcome of a
 * request (accepted, failed, unsupported, unknown). Transient states
 * (rate limited, paused, connection failed before sending) are not
 * events: nothing reached Safir.
 *
 * Event shape:
 *   event_key           "bale:{request_id}:{outcome}" — stable and unique; a
 *                       future implementation stores it under a UNIQUE index so
 *                       retries or repeated calls can never bill twice
 *   organization_id     int
 *   campaign_id         int (marketing_campaigns.id)
 *   request_id          string (Safir request_id = installation prefix + marketing_messages.id;
 *                       random for campaign test sends)
 *   provider_message_id ?string (Safir message_id, accepted only)
 *   outcome             accepted | failed | unsupported | unknown
 *   reason              fixed label, e.g. safir_17_not_bale_user
 *   occurred_at         UTC "Y-m-d H:i:s"
 *
 * Whether any of these is billable (acceptance only? unknown held until
 * reconciled?) is an open business decision — see docs/bale-messaging-module.md.
 * Implementations must be idempotent on event_key and must never throw
 * into the send path (BaleChannel catches and logs anyway).
 */
interface BillableMessageSink
{
    /** @param array{event_key: string, organization_id: int, campaign_id: int, request_id: string, provider_message_id: ?string, outcome: string, reason: string, occurred_at: string} $event */
    public function record(array $event): void;
}
