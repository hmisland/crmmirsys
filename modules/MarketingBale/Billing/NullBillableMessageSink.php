<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Billing;

/** Default sink: does nothing. No Wallet call, no ledger row, no price. */
final class NullBillableMessageSink implements BillableMessageSink
{
    public function record(array $event): void
    {
    }
}
