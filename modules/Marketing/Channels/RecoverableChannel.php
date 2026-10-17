<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

/**
 * Optional companion of MarketingChannelInterface: lets a channel decide
 * the queue-safety questions QueueWorker otherwise answers with its
 * built-in SMS / email rules. Channels without it behave exactly as
 * before.
 */
interface RecoverableChannel
{
    /**
     * Fields to finish a row with that a dead worker left in
     * "processing" (it may or may not have reached the provider), e.g.
     * ['status' => 'failed', 'ambiguous' => 1, 'error' => '…'] or
     * ['status' => 'pending'] when a resend is provably safe.
     */
    public function recoverStale(array $campaign, array $row, int $dedupeId): array;

    /** Whether an exception thrown by send() may mean the message went out. */
    public function exceptionIsAmbiguous(): bool;

    /** At most this many messages of one campaign per worker run / page tick. */
    public function maxPerRun(): int;

    /**
     * Whether an unknown (ambiguous) message may be put back in the
     * queue by an explicit, confirmed user action, reusing the same
     * provider dedupe id.
     */
    public function allowsConfirmedResend(): bool;
}
