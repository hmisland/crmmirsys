<?php

declare(strict_types=1);

namespace Modules\Contracts\Listeners;

use App\Application\Services\TimelineService;
use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use Modules\Contracts\ContractLinkRepository;
use Modules\Contracts\ContractsModule;
use Modules\Contracts\Services\ContractService;

/**
 * Deal → Contract through EventBus (step 2.7). Subscribed in
 * ContractsModule by the event's class NAME (a string) — this module
 * never imports Sales; if Sales isn't installed, nothing ever fires.
 *
 * The event is read by its public properties (organizationId, dealId,
 * stageName, isWon, isLost, actorUserId). If that shape ever changes,
 * the listener logs and does nothing (tests/lite cover the contract).
 *
 * Step 2.7: every contract linked to the deal gets a timeline entry.
 * Step 2.9: on "won", the organization's optional auto-create (off by
 * default) makes one draft contract linked to the deal.
 *
 * Runs with no acting-user permission check (system reaction, like the
 * Followups rule engine), scoped explicitly to the event's organization,
 * and only when Contracts is active and entitled for that organization.
 */
final class DealStageChangedListener implements ListenerInterface
{
    private const REQUIRED = ['organizationId', 'dealId', 'stageName', 'isWon', 'isLost', 'actorUserId'];

    public function __construct(
        private readonly ContractLinkRepository $links,
        private readonly TimelineService $timeline,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ContractService $contracts = null,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        foreach (self::REQUIRED as $property) {
            if (!property_exists($event, $property)) {
                $this->logger?->warning('Contracts: deal event has an unexpected shape; ignored', ['event' => $event::class, 'missing' => $property]);

                return;
            }
        }

        $organizationId = (int) $event->organizationId;
        if (!$this->contractsEnabledFor($organizationId)) {
            return;
        }

        $text = match (true) {
            (bool) $event->isWon => 'Linked deal won',
            (bool) $event->isLost => 'Linked deal lost',
            default => 'Linked deal moved to stage "' . (string) $event->stageName . '"',
        };
        $actor = $event->actorUserId !== null ? (int) $event->actorUserId : null;
        $dealId = (int) $event->dealId;

        $won = (bool) $event->isWon;

        OrganizationContext::runAs($organizationId, function () use ($dealId, $text, $actor, $won): void {
            foreach ($this->links->contractIdsLinkedTo('deal', $dealId) as $contractId) {
                $this->timeline->record('contract', $contractId, 'deal_stage_changed', $text, $actor);
            }

            // Step 2.9: the organization's optional "auto-create on won"
            // (the service checks the setting and "one contract per deal").
            if ($won && $this->contracts !== null) {
                $this->contracts->autoCreateFromWonDeal($dealId, $actor);
            }
        });
    }

    /** Organization-level check (there is no acting user to resolve per-user overrides for). */
    private function contractsEnabledFor(int $organizationId): bool
    {
        $module = (new ModuleRepository())->findByKey(ContractsModule::KEY);

        return $module !== null
            && $module['status'] === 'active'
            && (new OrganizationModuleRepository())->isEnabledForOrganization($organizationId, (int) $module['id']);
    }
}
