<?php

declare(strict_types=1);

namespace App\Core\Events;

/**
 * Marker interface for domain/application events (per Phase 0 §24–26/§59):
 * UserCreated, DealStageChanged, WalletCredited, KnowledgePublished, etc.
 * Concrete events are added alongside the module that raises them —
 * none are pre-declared here in Phase 1.
 */
interface EventInterface
{
}
