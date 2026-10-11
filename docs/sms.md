# SMS

One service sends every SMS in the app: `App\Application\Services\SmsService`.
Providers are pluggable per country/language, the same idea as payment
gateways: today **Kavenegar** (Iran, organizations whose default language is
`fa`).

## Providers (per country / language)

`App\Infrastructure\Sms\SmsProviderRegistry` holds one
`SmsProviderDefinition` per provider, registered in `Application.php`:

| Part | Meaning |
|---|---|
| `key`, `label` | e.g. `kavenegar`, "Kavenegar (کاوه‌نگار)" |
| `languages`, `countries` | which organizations it serves (by `organizations.default_language`) |
| `fields` | the credentials an account needs — `secret`, `required`, translated `label`/`hint` |
| `factory` | builds the `SmsSenderInterface` from those credentials |
| `signupUrl` | shown next to the fields |

Kavenegar's fields: `api_key` (secret, required), `sender` (required — the
line for Iranian numbers), `sender_international` (optional — Kavenegar's
separate line for numbers outside Iran). `KavenegarSmsSender` sends each
number on the matching line.

**Adding a provider for another country**: write a class implementing
`SmsSenderInterface` and register one more `SmsProviderDefinition`. Its
fields then appear automatically in Organization settings → SMS for
organizations in its language; nothing else changes. Organizations whose
language no provider serves don't see the SMS settings and cannot send.

## Platform account and organization accounts

Each organization chooses at **Organization settings → پیامک** (`/org/sms`,
needs `organization.edit`):

| Mode | Sent with | Paid how |
|---|---|---|
| `platform` (default) | the platform account (`config/sms.php` → `.env`), only if that provider serves the organization's language | per SMS part from the organization's prepaid credit — the Notification module's credit, charged from the org admin's Wallet (topped up through the payment gateway). Price per part = Super Admin's SMS cost at `/admin/notifications/settings`. Charged before sending, refunded if the provider rejects it. |
| `own` | the organization's own account at any provider serving its language | nothing here — they top up at the provider |

Platform account in `.env`:

```
SMS_PLATFORM_PROVIDER=kavenegar
KAVENEGAR_API_KEY=...
KAVENEGAR_SENDER=2000660110            # required by Kavenegar
KAVENEGAR_SENDER_INTERNATIONAL=        # optional
```

- An organization's own credentials are checked with the provider before
  saving and stored encrypted as one JSON object (`SecretBox`, AES-256-GCM;
  key from `APP_KEY`, or derived from the DB credentials if `APP_KEY` is
  unset — changing those means re-entering the credentials). Leaving a
  secret field empty on save keeps the stored value.
- Billable parts: Persian/Arabic text 70 characters in one part, 67 per
  part when longer; Latin 160 / 153. A template (verify) send = 1 part.
- Platform mode needs the Notifications module (and therefore Wallet) for
  the organization; without it, sends fail with "SMS credit is not
  available".
- Sends without an organization (Super Admin test, system jobs) use the
  platform account and are not billed.
- Super Admin → **پیامک** (`/admin/sms`): platform provider, lines,
  account credit, test send, log of every SMS.

## Using it from code

```php
use App\Application\Services\SmsService;

$sms = $container->make(SmsService::class);

// Free text to one or many numbers (0912…, +98912…, 98912…, Persian digits — all accepted)
$result = $sms->send('09121234567', 'متن پیام', 'appointments.reminder');
$result = $sms->send(['09121234567', '09351234567'], 'متن پیام', 'broadcast');

// OTP / verification code through a template defined in the provider's panel
$result = $sms->sendTemplate('09121234567', 'verify', ['48213'], 'auth.otp');

if (!$result->success) {
    // $result->errorMessage — the provider's own message
    // $result->statusCode   — Kavenegar: 403 bad key, 411 bad number, 412 bad/missing sender line,
    //                         418 no credit, 424 template not found; 402 = no SMS credit here
}
```

The account (platform or the organization's own) and billing are chosen
automatically from the current organization. The third argument
(`context`) labels the caller in the log. Template tokens are never logged.

## Log

`sms_logs` (migration `0068`): organization, user, provider
(`kavenegar` = platform, `kavenegar-own` = the organization's account),
receptor, kind, template name, message text, context, status, provider
message id, cost (Rials), error.
