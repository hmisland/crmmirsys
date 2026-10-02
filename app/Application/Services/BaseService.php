<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Infrastructure\Logging\LoggerInterface;

/**
 * Per Phase 0 §55/§58: Services own business logic/use-case
 * orchestration and are the ONLY layer allowed to call across module
 * boundaries — via another module's Service (synchronous) or by
 * dispatching an Event (decoupled). Services call Repositories for
 * persistence; they never write SQL themselves.
 */
abstract class BaseService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly EventBus $events,
    ) {
    }
}
