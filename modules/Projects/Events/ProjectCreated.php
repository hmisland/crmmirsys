<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use App\Core\Events\EventInterface;

/** Fired when a project is created (by hand, from a deal/contract, or automatically). Other modules may listen by class name. */
final class ProjectCreated implements EventInterface
{
    public function __construct(
        public readonly int $organizationId,
        public readonly int $projectId,
        public readonly string $code,
        public readonly ?int $actorUserId,
    ) {
    }
}
