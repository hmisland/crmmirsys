<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Infrastructure\Logging\LoggerInterface;

/**
 * Synchronous, in-process pub/sub (per Phase 0 §24–26/§59). This is
 * deliberately the ONLY sanctioned way one module reacts to another
 * module's state changes — e.g. Reporting listening for
 * DealStageChanged, or Notifications listening for TicketAssigned.
 * Modules must never call into each other's internals directly.
 *
 * Kept synchronous and in-memory for the shared-hosting MVP (no queue
 * daemon required, per Phase 0 §67–68). A listener throwing is logged
 * and swallowed rather than aborting the triggering request — a
 * notification failing to send must never roll back the business
 * transaction that raised the event.
 */
final class EventBus
{
    /** @var array<class-string, ListenerInterface[]> */
    private array $listeners = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function listen(string $eventClass, ListenerInterface $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(EventInterface $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            try {
                $listener->handle($event);
            } catch (\Throwable $e) {
                $this->logger?->error('Event listener failed', [
                    'event' => $event::class,
                    'listener' => $listener::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
