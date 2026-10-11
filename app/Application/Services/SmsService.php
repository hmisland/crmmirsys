<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\OrganizationSmsSettingsRepository;
use App\Infrastructure\Repositories\SmsLogRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\SecretBox;
use App\Infrastructure\Sms\NullSmsSender;
use App\Infrastructure\Sms\OrganizationAccountSender;
use App\Infrastructure\Sms\SmsBillingInterface;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Infrastructure\Sms\SmsResult;
use App\Infrastructure\Sms\SmsSenderInterface;

/**
 * The one place the app sends SMS from. Anywhere that needs a text
 * message resolves this from the container:
 *
 *   $sms = $container->make(SmsService::class);
 *   $sms->send('09121234567', 'متن پیام', 'followups.reminder');
 *   $sms->sendTemplate('09121234567', 'verify', ['12345'], 'auth.otp');
 *
 * Providers are pluggable per country/language, like payment gateways
 * (SmsProviderRegistry; Kavenegar serves Persian/Iran). For the current
 * organization, the account used is (organization_sms_settings, edited
 * at /org/sms):
 *  - 'platform' (default): the platform's provider account (config
 *    sms.platform) — only if that provider serves the organization's
 *    language; every part is paid from the organization's prepaid credit
 *    through SmsBillingInterface — charged before, refunded on failure;
 *  - 'own': the organization's own account at a provider serving its
 *    language — not billed here.
 * Organizations whose language no provider serves cannot send SMS.
 * Sends with no organization (Super Admin tests, system jobs) use the
 * platform account unbilled.
 *
 * Numbers are normalized (Persian/Arabic digits, +98 / 0098 / 98) and
 * every attempt is logged in sms_logs; template tokens (one-time codes)
 * are never written to the log.
 */
final class SmsService
{
    public const MODE_PLATFORM = 'platform';
    public const MODE_OWN = 'own';

    private ?SmsBillingInterface $billing = null;
    private ?SmsSenderInterface $platformSender = null;

    /**
     * @param array{provider?: string, credentials?: array<string, array<string, string>>} $platformConfig config sms.platform
     */
    public function __construct(
        private readonly SmsProviderRegistry $providers,
        private readonly array $platformConfig,
        private readonly SmsLogRepository $logs,
        private readonly OrganizationSmsSettingsRepository $orgSettings,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    /** Called by the Notification module when it is installed. */
    public function setBilling(SmsBillingInterface $billing): void
    {
        $this->billing = $billing;
    }

    public function billing(): ?SmsBillingInterface
    {
        return $this->billing;
    }

    // --- Platform account ----------------------------------------------------

    public function platformProvider(): ?SmsProviderDefinition
    {
        return $this->providers->get((string) ($this->platformConfig['provider'] ?? ''));
    }

    /** @return array<string, string> */
    public function platformCredentials(): array
    {
        $provider = $this->platformProvider();

        return $provider === null ? [] : array_map('strval', (array) ($this->platformConfig['credentials'][$provider->key] ?? []));
    }

    /** Whether the platform account is configured (all required fields set). */
    public function isConfigured(): bool
    {
        return $this->platformSender()->isConfigured();
    }

    public function providerName(): string
    {
        return $this->platformProvider()?->key ?? 'none';
    }

    /** @return array{credit: ?int, expires_at: ?int, error: ?string} platform account */
    public function accountInfo(): array
    {
        return $this->platformSender()->accountInfo();
    }

    // --- Organization settings ---------------------------------------------

    public function organizationLanguage(int $organizationId): string
    {
        return (string) ($this->organizations->findById($organizationId)['default_language'] ?? 'fa');
    }

    /** @return SmsProviderDefinition[] providers an organization may use with its own account */
    public function providersFor(int $organizationId): array
    {
        return $this->providers->forLanguage($this->organizationLanguage($organizationId));
    }

    /** Whether "our SMS service" is offered to this organization (platform provider serves its language). */
    public function platformAvailableFor(int $organizationId): bool
    {
        $provider = $this->platformProvider();

        return $provider !== null && $provider->servesLanguage($this->organizationLanguage($organizationId));
    }

    /**
     * @return array{mode: string, provider: string, values: array<string, string>, secret_hints: array<string, string>}
     *   values = non-secret fields; secret_hints = "••••1234" for each stored secret
     */
    public function organizationSettings(int $organizationId): array
    {
        $row = $this->orgSettings->find($organizationId);
        $provider = $this->providers->get((string) ($row['provider'] ?? 'kavenegar'));
        $credentials = $this->storedCredentials($row);
        $values = [];
        $hints = [];

        foreach ($provider?->fields ?? [] as $name => $field) {
            $value = (string) ($credentials[$name] ?? '');
            if (!empty($field['secret'])) {
                if ($value !== '') {
                    $hints[$name] = strlen($value) > 6 ? '••••' . substr($value, -4) : '••••';
                }
            } else {
                $values[$name] = $value;
            }
        }

        return [
            'mode' => ($row['mode'] ?? '') === self::MODE_OWN ? self::MODE_OWN : self::MODE_PLATFORM,
            'provider' => $provider?->key ?? '',
            'values' => $values,
            'secret_hints' => $hints,
        ];
    }

    /**
     * Saves the organization's choice. For "own", the submitted fields are
     * merged with the stored ones (an empty secret keeps the stored value),
     * required fields are enforced and the account is checked with the
     * provider before anything is stored.
     *
     * @param array<string, string> $input credential field values from the form
     * @return string|null error (translation key or provider message); null = saved
     */
    public function saveOrganizationSettings(int $organizationId, string $mode, string $providerKey, array $input, int $actorId): ?string
    {
        if ($mode !== self::MODE_OWN) {
            $this->orgSettings->save($organizationId, ['mode' => self::MODE_PLATFORM, 'updated_by_user_id' => $actorId]);

            return null;
        }

        $provider = $this->providers->get($providerKey);
        if ($provider === null || !$provider->servesLanguage($this->organizationLanguage($organizationId))) {
            return 'org_sms.error_provider_unavailable';
        }

        $row = $this->orgSettings->find($organizationId);
        $stored = ($row['provider'] ?? '') === $provider->key ? $this->storedCredentials($row) : [];
        $credentials = [];
        foreach ($provider->fields as $name => $field) {
            $value = trim((string) ($input[$name] ?? ''));
            $credentials[$name] = ($value === '' && !empty($field['secret'])) ? (string) ($stored[$name] ?? '') : $value;
        }

        if ($provider->missingRequired($credentials) !== []) {
            return 'org_sms.error_fields_required';
        }

        $error = $provider->make($credentials)->accountInfo()['error'];
        if ($error !== null) {
            return $error;
        }

        $this->orgSettings->save($organizationId, [
            'mode' => self::MODE_OWN,
            'provider' => $provider->key,
            'credentials_encrypted' => SecretBox::encrypt((string) json_encode($credentials, JSON_UNESCAPED_UNICODE)),
            'api_key_encrypted' => null,
            'sender' => null,
            'updated_by_user_id' => $actorId,
        ]);

        return null;
    }

    /** Account info of whichever account the organization sends with. */
    public function accountInfoFor(?int $organizationId): array
    {
        return $this->senderFor($organizationId)[0]->accountInfo();
    }

    // --- Sending -------------------------------------------------------------

    /**
     * @param string|string[] $receptors one number or several
     * @param string|null $context short label of the caller, e.g. 'followups.reminder'
     */
    public function send(string|array $receptors, string $message, ?string $context = null, ?string $sender = null): SmsResult
    {
        $message = trim($message);
        [$valid, $invalid] = $this->normalizeMany((array) $receptors);
        $organizationId = $this->currentOrganizationId();
        [$smsSender, $billable] = $this->senderFor($organizationId);

        if ($message === '') {
            return $this->logged(SmsResult::failure('Message is empty.'), $smsSender, $valid ?: $invalid, 'text', null, $message, $context);
        }
        if ($valid === []) {
            return $this->logged(SmsResult::failure('No valid mobile number.'), $smsSender, $invalid, 'text', null, $message, $context);
        }

        $units = self::partsFor($message) * count($valid);
        $charge = $this->charge($organizationId, $billable, $units, $context);
        if ($charge !== null) {
            return $this->logged($charge, $smsSender, $valid, 'text', null, $message, $context);
        }

        $result = $smsSender->send($valid, $message, $sender);
        $this->refundIfFailed($result, $organizationId, $billable, $units, $context);

        return $this->logged($result, $smsSender, $valid, 'text', null, $message, $context);
    }

    /**
     * A provider template message (Kavenegar verify/lookup) — use for
     * OTP / verification codes. The template is defined in the
     * provider's panel; $tokens fill %token, %token2, %token3.
     *
     * @param string[] $tokens
     */
    public function sendTemplate(string $receptor, string $template, array $tokens, ?string $context = null): SmsResult
    {
        $number = self::normalizeNumber($receptor);
        $organizationId = $this->currentOrganizationId();
        [$smsSender, $billable] = $this->senderFor($organizationId);

        if ($number === null) {
            return $this->logged(SmsResult::failure('No valid mobile number.'), $smsSender, [$receptor], 'template', $template, null, $context);
        }
        if (trim($template) === '' || $tokens === []) {
            return $this->logged(SmsResult::failure('Template name and at least one token are required.'), $smsSender, [$number], 'template', $template, null, $context);
        }

        $charge = $this->charge($organizationId, $billable, 1, $context);
        if ($charge !== null) {
            return $this->logged($charge, $smsSender, [$number], 'template', $template, null, $context);
        }

        $result = $smsSender->sendTemplate($number, trim($template), array_slice(array_map('strval', $tokens), 0, 3));
        $this->refundIfFailed($result, $organizationId, $billable, 1, $context);

        return $this->logged($result, $smsSender, [$number], 'template', $template, null, $context);
    }

    public function recentLog(int $limit = 50, ?int $organizationId = null): array
    {
        return $this->logs->recent($limit, $organizationId);
    }

    /**
     * Billable SMS parts for one recipient: Persian/Arabic (UCS-2) text
     * fits 70 characters in one part, 67 per part when split; plain
     * Latin text 160 / 153.
     */
    public static function partsFor(string $message): int
    {
        $length = mb_strlen($message);
        $unicode = (bool) preg_match('/[^\x00-\x7F]/', $message);
        [$single, $multi] = $unicode ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }

    /**
     * 09xxxxxxxxx for Iranian mobiles (from +98…, 0098…, 98…, 9…, with
     * Persian/Arabic digits, spaces or dashes), 00<country><number> for
     * other international numbers; null when it isn't a phone number.
     */
    public static function normalizeNumber(string $raw): ?string
    {
        $digits = strtr(trim($raw), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $international = str_starts_with($digits, '+') || str_starts_with($digits, '00');
        $digits = (string) preg_replace('/\D+/', '', $digits);

        if ($international) {
            $digits = ltrim($digits, '0');
            if (str_starts_with($digits, '98')) {
                $digits = '0' . substr($digits, 2);
            } else {
                return strlen($digits) >= 8 && strlen($digits) <= 15 ? '00' . $digits : null;
            }
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '98')) {
            $digits = '0' . substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0' . $digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    // --- Internals -----------------------------------------------------------

    private function platformSender(): SmsSenderInterface
    {
        if ($this->platformSender === null) {
            $provider = $this->platformProvider();
            $credentials = $this->platformCredentials();

            $this->platformSender = $provider !== null && $provider->missingRequired($credentials) === []
                ? $provider->make($credentials)
                : new NullSmsSender('The platform SMS account is not configured.');
        }

        return $this->platformSender;
    }

    /** @return array{0: SmsSenderInterface, 1: bool} sender, whether it is billed */
    private function senderFor(?int $organizationId): array
    {
        if ($organizationId === null) {
            return [$this->platformSender(), false];
        }

        $language = $this->organizationLanguage($organizationId);
        $row = $this->orgSettings->find($organizationId);

        if (($row['mode'] ?? '') === self::MODE_OWN) {
            $provider = $this->providers->get((string) ($row['provider'] ?? 'kavenegar'));
            $credentials = $this->storedCredentials($row);
            if ($provider !== null && $provider->servesLanguage($language) && $provider->missingRequired($credentials) === []) {
                return [new OrganizationAccountSender($provider->make($credentials)), false];
            }
        }

        if ($this->platformProvider()?->servesLanguage($language)) {
            return [$this->platformSender(), true];
        }

        return [new NullSmsSender('No SMS provider is available for this organization\'s language/region.'), false];
    }

    /** @return array<string, string> decrypted credentials of an organization's own account */
    private function storedCredentials(?array $row): array
    {
        if ($row === null) {
            return [];
        }

        $json = SecretBox::decrypt($row['credentials_encrypted'] ?? null);
        if ($json !== null) {
            return array_map('strval', (array) json_decode($json, true));
        }

        // Rows saved before migration 0070 (Kavenegar key + line).
        $legacyKey = SecretBox::decrypt($row['api_key_encrypted'] ?? null);

        return $legacyKey !== null ? ['api_key' => $legacyKey, 'sender' => (string) ($row['sender'] ?? '')] : [];
    }

    /** null = charged (or nothing to charge); otherwise the failure to report. */
    private function charge(?int $organizationId, bool $billable, int $units, ?string $context): ?SmsResult
    {
        if (!$billable || $organizationId === null) {
            return null;
        }

        if ($this->billing === null) {
            return SmsResult::failure('SMS credit is not available for this organization (Notifications module is not installed).', 402);
        }

        if (!$this->billing->tryCharge($organizationId, $units, (string) $context)) {
            return SmsResult::failure('Not enough SMS credit. Charge it from your wallet on the notifications page.', 402);
        }

        return null;
    }

    private function refundIfFailed(SmsResult $result, ?int $organizationId, bool $billable, int $units, ?string $context): void
    {
        if (!$result->success && $billable && $organizationId !== null && $this->billing !== null) {
            $this->billing->refund($organizationId, $units, (string) $context);
        }
    }

    private function currentOrganizationId(): ?int
    {
        return OrganizationContext::has() ? OrganizationContext::id() : null;
    }

    /** @return array{0: string[], 1: string[]} valid (deduplicated), invalid (as given) */
    private function normalizeMany(array $receptors): array
    {
        $valid = [];
        $invalid = [];
        foreach ($receptors as $raw) {
            $number = self::normalizeNumber((string) $raw);
            if ($number === null) {
                $invalid[] = (string) $raw;
            } else {
                $valid[$number] = $number;
            }
        }

        return [array_values($valid), $invalid];
    }

    /** @param string[] $receptors */
    private function logged(SmsResult $result, SmsSenderInterface $sender, array $receptors, string $kind, ?string $template, ?string $message, ?string $context): SmsResult
    {
        try {
            $organizationId = $this->currentOrganizationId();
            $receptors = $receptors === [] ? [''] : $receptors;

            foreach (array_values($receptors) as $i => $receptor) {
                $this->logs->create([
                    'organization_id' => $organizationId,
                    'user_id' => CurrentUser::id(),
                    'provider' => $sender->name(),
                    'receptor' => mb_substr($receptor, 0, 30),
                    'kind' => $kind,
                    'template' => $template !== null ? mb_substr($template, 0, 100) : null,
                    'message' => $message,
                    'context' => $context !== null ? mb_substr($context, 0, 100) : null,
                    'status' => $result->success ? 'sent' : 'failed',
                    'provider_message_id' => $result->messageIds[$i] ?? null,
                    // The provider reports one total for a multi-number send; keep it on the first row.
                    'cost' => $i === 0 ? $result->cost : 0,
                    'error' => $result->errorMessage !== null ? mb_substr($result->errorMessage, 0, 500) : null,
                ]);
            }
        } catch (\PDOException) {
            // sms_logs not migrated yet — sending must not depend on logging.
        }

        return $result;
    }
}
