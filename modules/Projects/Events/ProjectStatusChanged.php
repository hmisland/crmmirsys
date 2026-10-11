<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use App\Core\Events\EventInterface;

/** Fired on every lifecycle transition (plan, start, hold, resume, complete, cancel, reopen). */
final class ProjectStatusChanged implements EventInterface
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $projectId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $actorUserId,
    ) {
    }
}
