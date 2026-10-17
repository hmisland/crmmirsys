<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Core\Exceptions\AuthorizationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use Modules\Marketing\CampaignRepository;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\MessageRepository;

/** Dashboard, message history and basic analytics (read-only, marketing.view). */
final class InsightsService
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly MessageRepository $messages,
        private readonly SmsService $sms,
        private readonly EmailProviderService $email,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    /**
     * @return array{stats: array<string, array<string, int>>, recent: array, sms_ready: bool, email_providers: string[], queue_stalled: bool}
     */
    public function dashboard(array $user): array
    {
        $this->authorize($user);
        return [
            'stats' => $this->messages->statsLastDays(30),
            'recent' => $this->campaigns->page([], 5, 0),
            'sms_ready' => $this->sms->canSend(),
            'email_providers' => $this->email->connectedProviders(),
            // Pending for more than 10 minutes: probably no cron worker running.
            'queue_stalled' => $this->messages->hasPendingOlderThan(10),
        ];
    }

    public function history(array $user, array $filters, int $limit, int $offset): array
    {
        $this->authorize($user);

        return $this->messages->page($filters, $limit, $offset);
    }

    public function historyCount(array $user, array $filters): int
    {
        $this->authorize($user);

        return $this->messages->count($filters);
    }

    private function authorize(array $user): void
    {
        if (!$this->authorization->can($user, MarketingPermission::VIEW)) {
            throw new AuthorizationException();
        }
    }
}
