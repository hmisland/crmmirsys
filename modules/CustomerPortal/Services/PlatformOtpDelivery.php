<?php

declare(strict_types=1);

namespace Modules\CustomerPortal\Services;

use App\Application\Services\SmsService;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Mail\LogMailer;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Sign-in codes through the platform's own channels — never through
 * Marketing (whose opt-out lists would block a security message):
 *
 *  - email: the platform mailer (the one password resets use). With the
 *    "log" mail driver nothing is delivered and the message would be
 *    written to storage/mail, so outside the local environment email is
 *    reported unavailable (the sign-in falls back to SMS).
 *  - SMS: SmsService::sendTemplate(), the provider's verification
 *    template (Kavenegar verify/lookup) — the code is a template token and
 *    is never written to sms_logs (free-text SMS would be). Sent in the
 *    organization's context (its SMS account / credit).
 */
final class PlatformOtpDelivery implements OtpDelivery
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SmsService $sms,
        private readonly string $langPath,
    ) {
    }

    public function emailAvailable(): bool
    {
        return !$this->mailer instanceof LogMailer || Config::get('app.env') === 'local';
    }

    public function send(string $channel, string $destination, string $code, array $organization, string $locale): bool
    {
        if ($channel === self::EMAIL) {
            if (!$this->emailAvailable()) {
                return false;
            }
            $t = new Translator($locale, $this->langPath);
            $replace = ['code' => $code, 'organization' => (string) $organization['name'], 'minutes' => (string) (int) Config::get('customer_portal.otp_ttl_minutes', 5)];
            try {
                $this->mailer->send($destination, $t->get('customer_portal.email.subject', $replace), $t->get('customer_portal.email.body', $replace));

                return true;
            } catch (\Throwable) {
                return false;
            }
        }
        if ($channel === self::SMS) {
            $template = (string) Config::get('customer_portal.sms_template', 'verify');

            return OrganizationContext::runAs((int) $organization['id'], fn (): bool => $this->sms->sendTemplate($destination, $template, [$code], 'customer_portal.otp')->success);
        }

        return false;
    }
}
