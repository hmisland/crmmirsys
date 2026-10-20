<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

/**
 * Customer Portal sign-in (modules/CustomerPortal). Codes are 6 digits.
 */
return [
    // How long a sign-in code works, and how many wrong tries it allows.
    'otp_ttl_minutes' => max(1, min(30, (int) env('CUSTOMER_PORTAL_OTP_TTL_MINUTES', 5))),
    'otp_max_attempts' => max(1, min(10, (int) env('CUSTOMER_PORTAL_OTP_MAX_ATTEMPTS', 5))),
    // Wait before another code can be sent to the same customer, and codes per customer per hour.
    'resend_cooldown_seconds' => max(15, (int) env('CUSTOMER_PORTAL_RESEND_COOLDOWN_SECONDS', 60)),
    'codes_per_contact_per_hour' => max(1, (int) env('CUSTOMER_PORTAL_CODES_PER_CONTACT_PER_HOUR', 5)),
    // Kavenegar verify/lookup template that receives the code as %token.
    'sms_template' => env('CUSTOMER_PORTAL_SMS_TEMPLATE', 'verify'),
];
