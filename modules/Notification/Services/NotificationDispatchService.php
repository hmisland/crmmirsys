<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use App\Infrastructure\Repositories\NotificationPlatformSettingsRepository;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Notification\Gateways\BaleGateway;
use Modules\Notification\Gateways\NotificationGatewayInterface;
use Modules\Notification\Gateways\NotificationSendResult;
use Modules\Notification\NotificationLogRepository;
use Modules\Notification\NotificationRecipientRepository;

/**
 * Called when the compose/reply "notify" checkbox is checked (per the
 * explicit requirement) — never with the full message text, only a
 * short, fixed announcement ("You have a new message in..."), since
 * that's all that was asked for. Tries BOTH channels for each
 * recipient (not "pick one") — SMS goes through the core SmsService
 * (Kavenegar) once KAVENEGAR_API_KEY is set; Bale sends if the
 * recipient has stored a chat_id and the bot token is configured.
 */
final class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationRecipientRepository $recipients,
        private readonly NotificationCreditService $credits,
        private readonly NotificationLogRepository $logs,
        private readonly NotificationPlatformSettingsRepository $platformSettings,
        private readonly BaleGateway $bale,
        private readonly NotificationGatewayInterface $sms,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param int[] $recipientUserIds
     */
    public function notifyNewMessage(int $organizationId, array $recipientUserIds, ?int $referenceMessageId, string $appName): void
    {
        $text = "شما یک پیام جدید در {$appName} دارید. برای مشاهده وارد پنل شوید.";
        $costs = $this->platformSettings->get();

        foreach ($recipientUserIds as $userId) {
            $this->sendOneChannel($organizationId, $userId, $this->bale, (string) $costs['bale_cost'], $text, $referenceMessageId);
            $this->sendOneChannel($organizationId, $userId, $this->sms, (string) $costs['sms_cost'], $text, $referenceMessageId);
        }
    }

    private function sendOneChannel(int $organizationId, int $userId, NotificationGatewayInterface $gateway, string $cost, string $text, ?int $referenceId): void
    {
        if ($gateway->channel() === 'bale') {
            $destination = $this->recipients->find($userId);
            $value = $destination['bale_chat_id'] ?? null;
        } else {
            // SMS destination is the user's own profile phone number
            // (users.mobile_phone) — the single source of truth, set
            // from the Profile page, not a separate copy this module
            // used to keep in notification_recipients.phone_number.
            $user = $this->users->findById($userId);
            $value = $user['mobile_phone'] ?? null;
        }

        if ($value === null || trim($value) === '') {
            $this->logs->record($userId, $gateway->channel(), 'skipped_no_destination', null, 'message', $referenceId);

            return;
        }

        // SMS is billed inside SmsService (platform account → this credit,
        // organization's own Kavenegar key → nothing); only Bale is
        // charged here, so an SMS is never paid twice.
        if ($gateway->channel() !== 'sms' && !$this->credits->tryDeduct($organizationId, $cost, $gateway->channel(), $referenceId)) {
            $this->logs->record($userId, $gateway->channel(), 'skipped_no_credit', null, 'message', $referenceId);

            return;
        }

        $result = $gateway->send($value, $text);

        $this->logs->record(
            $userId,
            $gateway->channel(),
            $result->success ? 'sent' : 'failed',
            $result->errorMessage,
            'message',
            $referenceId
        );
    }

    public function recentLogs(int $limit = 100): array
    {
        return $this->logs->recent($limit);
    }

    /**
     * A free connectivity/config check — deliberately bypasses credit
     * deduction and notification_logs (it's not a real customer
     * notification, just "does this chat_id/token combination work
     * at all"). Returns the gateway's own error message on failure so
     * the person testing can see exactly what Bale said.
     */
    public function sendTestBale(string $destination, string $appName): NotificationSendResult
    {
        return $this->bale->send($destination, "این یک پیام آزمایشی از {$appName} است.");
    }
}
