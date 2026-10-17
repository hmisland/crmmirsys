<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Services;

use App\Application\Services\TimelineService;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\SecretBox;
use Modules\MarketingBale\BaleSettingsRepository;

/**
 * The organization's Safir account (Organization settings → Messaging
 * providers → Bale). The api-access-key is encrypted with SecretBox, is
 * never returned by view(), never logged and never written to the
 * timeline; only its last 4 characters are kept as a hint.
 *
 * Safir has no documented connection-test or account endpoint, so saving
 * only checks the format: the status stays "configured" (not verified)
 * until Safir accepts a message with these credentials ("verified"), or
 * refuses the key ("rejected", which blocks sending until it is replaced).
 */
final class BaleSettingsService
{
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_CONFIGURED = 'configured';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    private const BOT_ID_MAX = 9_223_372_036_854_775_807;

    public function __construct(
        private readonly BaleSettingsRepository $settings,
        private readonly TimelineService $timeline,
    ) {
    }

    /** @return array{status: string, enabled: bool, bot_id: ?int, has_key: bool, key_hint: ?string, last_success_at: ?string, last_error: ?string, paused_until: ?string, pause_reason: ?string} */
    public function view(): array
    {
        $row = $this->settings->current() ?? [];

        return [
            'status' => (string) ($row['status'] ?? self::STATUS_DISCONNECTED),
            'enabled' => !isset($row['enabled']) || (int) $row['enabled'] === 1,
            'bot_id' => isset($row['bot_id']) ? (int) $row['bot_id'] : null,
            'has_key' => !empty($row['api_key_encrypted']),
            'key_hint' => $row['api_key_hint'] ?? null,
            'last_success_at' => $row['last_success_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'paused_until' => $this->activePause($row) ? (string) $row['paused_until'] : null,
            'pause_reason' => $this->activePause($row) ? (string) $row['pause_reason'] : null,
        ];
    }

    /**
     * Saves bot id and (optionally) a new key. An empty key keeps the
     * stored one. Never contacts Safir and never sends a message.
     *
     * @return string|null translation key of the problem
     */
    public function save(string $botIdRaw, string $apiKeyRaw, int $userId): ?string
    {
        $botIdRaw = trim($botIdRaw);
        $apiKey = trim($apiKeyRaw);
        if (preg_match('/^[1-9]\d{0,18}$/', $botIdRaw) !== 1 || (float) $botIdRaw > self::BOT_ID_MAX) {
            return 'bale.error.bot_id_invalid';
        }
        $botId = (int) $botIdRaw;
        $row = $this->settings->current();
        $hasKey = !empty($row['api_key_encrypted']);

        if ($apiKey === '' && !$hasKey) {
            return 'bale.error.key_required';
        }
        if ($apiKey !== '' && (strlen($apiKey) < 8 || strlen($apiKey) > 512 || preg_match('/^[\x21-\x7E]+$/', $apiKey) !== 1)) {
            return 'bale.error.key_invalid';
        }

        $fields = ['bot_id' => $botId, 'updated_by_user_id' => $userId];
        $changed = $apiKey !== '' || (int) ($row['bot_id'] ?? 0) !== $botId;
        if ($apiKey !== '') {
            $fields['api_key_encrypted'] = SecretBox::encrypt($apiKey);
            $fields['api_key_hint'] = substr($apiKey, -4);
        }
        if ($changed || ($row['status'] ?? self::STATUS_DISCONNECTED) === self::STATUS_DISCONNECTED) {
            // New credentials are unproven until Safir accepts a message with them.
            $fields += ['status' => self::STATUS_CONFIGURED, 'last_error' => null, 'paused_until' => null, 'pause_reason' => null];
        }
        $this->settings->save($fields);
        $this->timeline->record('organization', OrganizationContext::id(), 'bale.settings_saved', $apiKey !== '' ? 'Bale (Safir) credentials saved' : 'Bale (Safir) bot id saved', $userId);

        return null;
    }

    /** Removes the stored key and bot id; Bale sending stops until new credentials are saved. */
    public function disconnect(int $userId): void
    {
        if ($this->settings->current() === null) {
            return;
        }
        $this->settings->save([
            'api_key_encrypted' => null, 'api_key_hint' => null, 'bot_id' => null,
            'status' => self::STATUS_DISCONNECTED, 'last_error' => null, 'paused_until' => null, 'pause_reason' => null,
            'updated_by_user_id' => $userId,
        ]);
        $this->timeline->record('organization', OrganizationContext::id(), 'bale.disconnected', 'Bale (Safir) credentials removed', $userId);
    }

    /** Turns sending off (or on again) without touching the stored credentials. */
    public function setEnabled(bool $enabled, int $userId): void
    {
        if ($this->settings->current() === null) {
            return;
        }
        $this->settings->save(['enabled' => $enabled ? 1 : 0, 'updated_by_user_id' => $userId]);
        $this->timeline->record('organization', OrganizationContext::id(), $enabled ? 'bale.enabled' : 'bale.disabled', $enabled ? 'Bale sending enabled' : 'Bale sending disabled', $userId);
    }

    /**
     * Why the current organization cannot send on Bale now; null = ready.
     * Checked by Marketing before approval and by the worker before
     * every batch.
     */
    public function unavailableReason(): ?string
    {
        $row = $this->settings->current();
        if ($row === null || empty($row['api_key_encrypted']) || empty($row['bot_id']) || $row['status'] === self::STATUS_DISCONNECTED) {
            return 'bale.error.not_configured';
        }
        if ((int) $row['enabled'] !== 1) {
            return 'bale.error.disabled';
        }
        if ($row['status'] === self::STATUS_REJECTED) {
            return 'bale.error.credentials_rejected';
        }
        if ($this->activePause($row)) {
            return 'bale.pause.' . $row['pause_reason'];
        }

        return null;
    }

    /** @return array{bot_id: int, api_key: string}|null decrypted credentials of the CURRENT organization, only when usable */
    public function credentials(): ?array
    {
        if ($this->unavailableReason() !== null) {
            return null;
        }
        $row = (array) $this->settings->current();
        $key = SecretBox::decrypt($row['api_key_encrypted'] ?? null);
        if ($key === null || $key === '') {
            return null;
        }

        return ['bot_id' => (int) $row['bot_id'], 'api_key' => $key];
    }

    /** Safir accepted a message: the credentials work. */
    public function recordAccepted(): void
    {
        $row = $this->settings->current();
        if ($row === null) {
            return;
        }
        $fields = ['last_success_at' => gmdate('Y-m-d H:i:s')];
        if ($row['status'] !== self::STATUS_VERIFIED) {
            $fields += ['status' => self::STATUS_VERIFIED, 'last_error' => null];
        }
        $this->settings->save($fields);
    }

    /** Safir refused the key: block sending until an admin saves new credentials. */
    public function recordRejected(): void
    {
        if ($this->settings->current() === null) {
            return;
        }
        $this->settings->save(['status' => self::STATUS_REJECTED, 'last_error' => 'bale.error.credentials_rejected']);
    }

    /**
     * Organization-wide back-off (rate limit, payment required, contact
     * limit): every Bale campaign of the organization waits, nothing is
     * marked failed. $reason is a SafirOutcome reason label.
     */
    public function pause(int $seconds, string $reason): void
    {
        if ($this->settings->current() === null) {
            return;
        }
        $this->settings->save([
            'paused_until' => gmdate('Y-m-d H:i:s', time() + max(1, $seconds)),
            'pause_reason' => mb_substr($reason, 0, 60),
            'last_error' => 'bale.pause.' . mb_substr($reason, 0, 60),
        ]);
    }

    private function activePause(array $row): bool
    {
        return !empty($row['paused_until']) && strtotime((string) $row['paused_until'] . ' UTC') > time();
    }
}
