<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Repositories\TimelineEventRepository;

/**
 * Per Phase 0 §18: a unified activity feed. Any Service can call
 * record() after a meaningful action — this is intentionally NOT
 * wired through EventBus/listeners for Phase 5 (that would be the
 * more "correct" decoupled design per Phase 0 §59, but a direct call
 * is simpler and sufficient while only CRM Core writes to it; revisit
 * if/when multiple modules need to react to the same action
 * independently).
 */
final class TimelineService
{
    public function __construct(private readonly TimelineEventRepository $events)
    {
    }

    public function record(string $subjectType, int $subjectId, string $type, string $description, ?int $actorUserId): void
    {
        $this->events->record($subjectType, $subjectId, $type, $description, $actorUserId);
    }

    public function forSubject(string $subjectType, int $subjectId): array
    {
        return $this->events->forSubject($subjectType, $subjectId);
    }
}
