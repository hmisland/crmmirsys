<?php

declare(strict_types=1);

namespace Modules\Contracts\Events;

use App\Core\Events\EventInterface;

/** Fired when an active contract is terminated. */
final class ContractTerminated implements EventInterface
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $contractId,
        public readonly ?int $actorUserId,
    ) {
    }
}
