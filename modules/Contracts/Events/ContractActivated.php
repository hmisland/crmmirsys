<?php

declare(strict_types=1);

namespace Modules\Contracts\Events;

use App\Core\Events\EventInterface;

/** Fired when a contract becomes active (activate() or approve()). Other modules may listen by class name. */
final class ContractActivated implements EventInterface
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $contractId,
        public readonly string $contractNumber,
        public readonly ?int $actorUserId,
    ) {
    }
}
