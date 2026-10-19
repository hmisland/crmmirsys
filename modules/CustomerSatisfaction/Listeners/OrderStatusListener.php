<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Infrastructure\Integrations\Events\ExternalOrderStatusChanged;
use Modules\CustomerSatisfaction\Services\InvitationService;

/** Any external order source → "order completed" → scheduled survey invitations (if the organization has such a survey). */
final class OrderStatusListener implements ListenerInterface
{
    public function __construct(private readonly InvitationService $invitations)
    {
    }

    public function handle(EventInterface $event): void
    {
        if ($event instanceof ExternalOrderStatusChanged) {
            $this->invitations->fromOrderEvent($event);
        }
    }
}
