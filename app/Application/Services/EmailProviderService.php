<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Email\EmailConnectionResult;
use App\Infrastructure\Email\EmailMessage;
use App\Infrastructure\Email\EmailProviderDefinition;
use App\Infrastructure\Email\EmailProviderInterface;
use App\Infrastructure\Email\EmailProviderRegistry;
use App\Infrastructure\Email\EmailSendResult;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationEmailProviderRepository;
use App\Infrastructure\Security\SecretBox;

/**
 * The current organization's own email provider accounts (Sender,
 * Brevo): saving, testing, disconnecting, the test email and webhook
 * registration — and, for Marketing, the adapter to send with.
 *
 * Secrets: encrypted with SecretBox before storage, never returned
 * (only a "••••1234" hint), never logged; provider error texts are the
 * provider's own messages, which do not contain the key.
 *
 * Entitlement (the marketing_email module) is enforced by the callers:
 * the settings controller and the Marketing module. This service only
 * ever works inside OrganizationContext (tenant-scoped repository).
 */
final class EmailProviderService
{
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISCONNECTED = 'disconnected';

    public function __construct(
        private readonly EmailProviderRegistry $registry,
        private readonly OrganizationEmailProviderRepository $repository,
        private readonly TimelineService $timeline,
        private readonly string $appUrl,
    ) {
    }

    /** @return EmailProviderDefinition[] */
    public function definitions(): array
    {
        return $this->registry->all();
    }

    public function definition(string $provider): ?EmailProviderDefinition
    {
        return $this->registry->get($provider);
    }

    /**
     * View data per provider — never the secret itself.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(): array
    {
        $rows = $this->repository->allForOrganization();
        $result = [];
        foreach ($this->registry->all() as $definition) {
            $row = $rows[$definition->key] ?? [];
            $result[$definition->key] = [
                'status' => (string) ($row['status'] ?? self::STATUS_DISCONNECTED),
                'has_secret' => !empty($row['secret_encrypted']),
                'secret_hint' => !empty($row['secret_hint']) ? '••••' . $row['secret_hint'] : '',
                'sender_email' => (string) ($row['sender_email'] ?? ''),
                'sender_name' => (string) ($row['sender_name'] ?? ''),
                'reply_to' => (string) ($row['reply_to'] ?? ''),
                'sender_verified' => isset($row['sender_verified']) ? (bool) $row['sender_verified'] : null,
                'last_tested_at' => $row['last_tested_at'] ?? null,
                'last_success_at' => $row['last_success_at'] ?? null,
                'last_error' => $row['last_error'] ?? null,
                'webhook_url' => !empty($row['webhook_key']) ? $this->webhookUrl($definition->key, (string) $row['webhook_key']) : null,
                'webhook_registered_at' => $row['webhook_registered_at'] ?? null,
                'capabilities' => $definition->make('')->capabilities(),
            ];
        }

        return $result;
    }

    /**
     * Saves sender identity and (optionally) a new secret. A new or
     * replaced secret is checked with the provider first; if the check
     * fails nothing is stored and the error is returned.
     *
     * @param array{api_secret?: string, sender_email?: string, sender_name?: string, reply_to?: string} $input
     * @return array{error: ?string, result: ?EmailConnectionResult} error = translation key or provider message
     */
    public function save(string $provider, array $input, int $actorId): array
    {
        $definition = $this->registry->get($provider);
        if ($definition === null) {
            return ['error' => 'email_providers.error_unknown_provider', 'result' => null];
        }

        $senderEmail = trim((string) ($input['sender_email'] ?? ''));
        $senderName = trim((string) ($input['sender_name'] ?? ''));
        $replyTo = trim((string) ($input['reply_to'] ?? ''));
        $newSecret = trim((string) ($input['api_secret'] ?? ''));

        if ($senderEmail === '' || filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'email_providers.error_sender_email', 'result' => null];
        }
        if ($senderName === '' || mb_strlen($senderName) > 150) {
            return ['error' => 'email_providers.error_sender_name', 'result' => null];
        }
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'email_providers.error_reply_to', 'result' => null];
        }
        // Sender tokens are long JWTs (~1,100 characters); Brevo keys are short.
        if (strlen($newSecret) > 8192 || preg_match('/\s/', $newSecret) === 1) {
            return ['error' => 'email_providers.error_secret_invalid', 'result' => null];
        }

        $row = $this->repository->find($provider);
        $secret = $newSecret !== '' ? $newSecret : (string) SecretBox::decrypt($row['secret_encrypted'] ?? null);
        if ($secret === '') {
            return ['error' => 'email_providers.error_secret_required', 'result' => null];
        }

        $result = $definition->make($secret)->testConnection($senderEmail);
        if (!$result->ok) {
            if ($newSecret === '' && $row !== null) {
                $this->repository->save($provider, ['status' => self::STATUS_ERROR, 'last_tested_at' => $this->now(), 'last_error' => mb_substr((string) $result->error, 0, 500)]);
            }

            return ['error' => (string) $result->error, 'result' => $result];
        }

        $fields = [
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'reply_to' => $replyTo !== '' ? $replyTo : null,
            'status' => self::STATUS_CONNECTED,
            'sender_verified' => $result->senderVerified === null ? null : (int) $result->senderVerified,
            'last_tested_at' => $this->now(),
            'last_success_at' => $this->now(),
            'last_error' => null,
            'updated_by_user_id' => $actorId,
        ];
        if ($newSecret !== '') {
            $fields['secret_encrypted'] = SecretBox::encrypt($newSecret);
            $fields['secret_hint'] = strlen($newSecret) > 8 ? substr($newSecret, -4) : '';
        }

        $this->repository->save($provider, $fields);
        $this->audit($newSecret !== '' ? 'email_provider.credentials_saved' : 'email_provider.settings_saved', $provider, $actorId);

        return ['error' => null, 'result' => $result];
    }

    /** Re-checks the stored credentials and records the outcome. */
    public function test(string $provider, int $actorId): EmailConnectionResult
    {
        $row = $this->repository->find($provider);
        $adapter = $this->adapterFromRow($provider, $row);
        if ($adapter === null) {
            return EmailConnectionResult::failure('email_providers.error_not_configured');
        }

        $result = $adapter->testConnection((string) ($row['sender_email'] ?? ''));
        $fields = ['last_tested_at' => $this->now(), 'status' => $result->ok ? self::STATUS_CONNECTED : self::STATUS_ERROR];
        if ($result->ok) {
            $fields['last_success_at'] = $this->now();
            $fields['last_error'] = null;
            $fields['sender_verified'] = $result->senderVerified === null ? null : (int) $result->senderVerified;
        } else {
            $fields['last_error'] = mb_substr((string) $result->error, 0, 500);
        }
        $this->repository->save($provider, $fields);
        $this->audit('email_provider.tested', $provider, $actorId);

        return $result;
    }

    /** Forgets the stored secret and webhook; the row stays for history. */
    public function disconnect(string $provider, int $actorId): void
    {
        if ($this->repository->find($provider) === null) {
            return;
        }

        $this->repository->save($provider, [
            'secret_encrypted' => null,
            'secret_hint' => null,
            'status' => self::STATUS_DISCONNECTED,
            'sender_verified' => null,
            'last_error' => null,
            'webhook_key' => null,
            'webhook_secret_encrypted' => null,
            'webhook_registered_at' => null,
            'updated_by_user_id' => $actorId,
        ]);
        $this->audit('email_provider.disconnected', $provider, $actorId);
    }

    /** A real email, sent only when a user explicitly asks for it. */
    public function sendTest(string $provider, string $to, string $subject, string $html, int $actorId): EmailSendResult
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return EmailSendResult::failure('email_providers.error_test_address');
        }

        $row = $this->repository->find($provider);
        $adapter = $this->connectedAdapter($provider);
        if ($adapter === null || $row === null) {
            return EmailSendResult::failure('email_providers.error_not_configured');
        }

        $result = $adapter->send(new EmailMessage(
            $to, '', (string) $row['sender_email'], (string) $row['sender_name'], $subject, $html, strip_tags($html),
            $row['reply_to'] ?? null, [], ['crm-test'], EmailMessage::CLASS_TRANSACTIONAL
        ));
        $this->audit($result->success ? 'email_provider.test_email_sent' : 'email_provider.test_email_failed', $provider, $actorId);

        return $result;
    }

    /**
     * Creates (or rotates) our webhook URL + bearer token and registers
     * it with the provider. Returns null on success, otherwise an error.
     */
    public function registerWebhook(string $provider, int $actorId): ?string
    {
        $adapter = $this->connectedAdapter($provider);
        if ($adapter === null) {
            return 'email_providers.error_not_configured';
        }
        if (empty($adapter->capabilities()[EmailProviderInterface::CAP_WEBHOOK_REGISTRATION])) {
            return 'email_providers.error_webhook_unsupported';
        }
        if (!str_starts_with($this->appUrl, 'https://')) {
            return 'email_providers.error_webhook_https';
        }

        $key = bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(24));
        $result = $adapter->registerWebhook($this->webhookUrl($provider, $key), $secret);
        if (!$result->ok) {
            return (string) $result->error;
        }

        $this->repository->save($provider, [
            'webhook_key' => $key,
            'webhook_secret_encrypted' => SecretBox::encrypt($secret),
            'webhook_registered_at' => $this->now(),
        ]);
        $this->audit('email_provider.webhook_registered', $provider, $actorId);

        return null;
    }

    /**
     * Webhook authentication: the URL key picks the organization, the
     * bearer token must match its stored secret (constant-time).
     * Returns the organization id, or null when not authentic.
     */
    public function authenticateWebhook(string $provider, string $key, string $authorizationHeader): ?int
    {
        $row = $this->repository->findByWebhookKey($provider, $key);
        if ($row === null) {
            return null;
        }

        $secret = SecretBox::decrypt($row['webhook_secret_encrypted'] ?? null);
        $given = preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $m) === 1 ? trim($m[1]) : '';
        if ($secret === null || $secret === '' || $given === '' || !hash_equals($secret, $given)) {
            return null;
        }

        return (int) $row['organization_id'];
    }

    /** The adapter for sending, only when the account is connected. */
    public function connectedAdapter(string $provider): ?EmailProviderInterface
    {
        $row = $this->repository->find($provider);
        if ($row === null || ($row['status'] ?? '') !== self::STATUS_CONNECTED) {
            return null;
        }

        return $this->adapterFromRow($provider, $row);
    }

    /** @return array{email: string, name: string, reply_to: ?string}|null */
    public function senderIdentity(string $provider): ?array
    {
        $row = $this->repository->find($provider);
        if ($row === null || empty($row['sender_email'])) {
            return null;
        }

        return ['email' => (string) $row['sender_email'], 'name' => (string) $row['sender_name'], 'reply_to' => $row['reply_to'] ?? null];
    }

    /** @return string[] keys of connected providers */
    public function connectedProviders(): array
    {
        $keys = [];
        foreach ($this->repository->allForOrganization() as $key => $row) {
            if (($row['status'] ?? '') === self::STATUS_CONNECTED && !empty($row['secret_encrypted']) && $this->registry->get($key) !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function webhookUrl(string $provider, string $key): string
    {
        return rtrim($this->appUrl, '/') . '/webhooks/email/' . rawurlencode($provider) . '/' . rawurlencode($key);
    }

    private function adapterFromRow(string $provider, ?array $row): ?EmailProviderInterface
    {
        $definition = $this->registry->get($provider);
        $secret = SecretBox::decrypt($row['secret_encrypted'] ?? null);

        return $definition === null || $secret === null || $secret === '' ? null : $definition->make($secret);
    }

    private function audit(string $type, string $provider, int $actorId): void
    {
        if (!OrganizationContext::has()) {
            return;
        }

        // Organization-level audit trail through the existing timeline; never the secret.
        $this->timeline->record('organization', OrganizationContext::id(), $type, $type . ': ' . $provider, $actorId > 0 ? $actorId : null);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
