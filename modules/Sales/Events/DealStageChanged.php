<?php

declare(strict_types=1);

namespace Modules\Sales\Events;

use App\Core\Events\EventInterface;

final class DealStageChanged implements EventInterface
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $dealId,
        public readonly int $stageId,
        public readonly string $stageName,
        public readonly bool $isWon,
        public readonly bool $isLost,
        public readonly ?int $actorUserId,
    ) {
    }
}
