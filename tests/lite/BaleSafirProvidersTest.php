<?php

declare(strict_types=1);

/**
 * Bale / Safir provider layer without a database: request shape,
 * number normalization, and the mapping of every documented Safir
 * error code and HTTP-level outcome. All HTTP goes to FakeSafirTransport.
 */

use Modules\MarketingBale\Safir\CurlSafirTransport;
use Modules\MarketingBale\Safir\SafirClient;
use Modules\MarketingBale\Safir\SafirHttpResponse;
use Modules\MarketingBale\Safir\SafirOutcome;
use Modules\MarketingBale\Safir\SafirPhone;
use Modules\MarketingBale\Channels\BaleChannel;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\Channels\PhoneTextChannel;
use Modules\Marketing\Channels\RecoverableChannel;
use Modules\Marketing\Channels\SmsChannel;
use Modules\Marketing\Channels\EmailChannel;
use Modules\Marketing\Channels\DispatchResult;
use Modules\Marketing\Services\AudienceResolver;
use Tests\Lite\Fixtures\FakeSafirTransport;

require_once __DIR__ . '/Fixtures/BaleFakes.php';

$persian = "سلام علی جان 👋\nتخفیف ۲۰٪ برای شما — «ویژه»";

return [
    'request: POST {base}/send_message, api-access-key header, JSON with int bot_id, 98-number, Persian text intact, request_id' => function () use ($persian) {
        $t = new FakeSafirTransport();
        $client = new SafirClient($t);
        $out = $client->sendText('lite-key-ABCD1234', 123456789, '989121234567', $persian, 'crm-42');

        LiteAssert::true($out->isAccepted());
        LiteAssert::same('lite-1', $out->messageId);
        $r = $t->last();
        LiteAssert::same('https://safir.bale.ai/api/v3/send_message', $r['url']);
        LiteAssert::true(in_array('api-access-key: lite-key-ABCD1234', $r['headers'], true), 'auth header');
        LiteAssert::true(in_array('Content-Type: application/json; charset=utf-8', $r['headers'], true));
        LiteAssert::same(['request_id' => 'crm-42', 'bot_id' => 123456789, 'phone_number' => '989121234567', 'message_data' => ['message' => ['text' => $persian]]], $r['body']);
        LiteAssert::true(is_int($r['body']['bot_id']), 'bot_id is an integer');
        LiteAssert::contains('سلام علی جان', $r['raw'], 'UTF-8 sent unescaped');
        LiteAssert::notContains('\\u', $r['raw']);
        LiteAssert::false(isset($r['body']['message_data']['is_secure']) || isset($r['body']['message_data']['otp_message']) || isset($r['body']['message_data']['template_message']), 'text only');
    },

    'request: nothing is sent for invalid input (non-98 number, empty or broken text, missing key/bot, bad request id)' => function () {
        $t = new FakeSafirTransport();
        $client = new SafirClient($t);
        LiteAssert::same('failed', $client->sendText('k-12345678', 1, '09121234567', 'x', 'r')->kind);
        LiteAssert::same('failed', $client->sendText('k-12345678', 1, '+989121234567', 'x', 'r')->kind);
        LiteAssert::same('failed', $client->sendText('k-12345678', 1, '989121234567', '', 'r')->kind);
        LiteAssert::same('failed', $client->sendText('k-12345678', 1, '989121234567', "\xC3\x28", 'r')->kind, 'invalid UTF-8');
        LiteAssert::same('failed', $client->sendText('k-12345678', 1, '989121234567', 'x', str_repeat('r', 65))->kind);
        LiteAssert::same('pause', $client->sendText('', 1, '989121234567', 'x', 'r')->kind);
        LiteAssert::same('pause', $client->sendText('k-12345678', 0, '989121234567', 'x', 'r')->kind);
        LiteAssert::same(0, count($t->requests), 'transport never called');
    },

    'phone: CRM forms → 98 + 10 digits; malformed rejected; foreign numbers unsupported, never made Iranian' => function () {
        foreach ([
            '09121234567' => '989121234567',
            '+989121234567' => '989121234567',
            '00989121234567' => '989121234567',
            '989121234567' => '989121234567',
            '9121234567' => '989121234567',
            '0912 123 4567' => '989121234567',
            '۰۹۱۲۱۲۳۴۵۶۷' => '989121234567',
            '٠٩١٢١٢٣٤٥٦٧' => '989121234567',
            '+98 (912) 123-4567' => '989121234567',
        ] as $in => $out) {
            LiteAssert::same([$out, null], SafirPhone::classify((string) $in), (string) $in);
        }
        foreach (['', '12345', '0912123456', '091212345678', '02112345678', 'abc', '+98912123'] as $bad) {
            LiteAssert::same([null, 'invalid_address'], SafirPhone::classify($bad), 'invalid: ' . $bad);
        }
        foreach (['+447911123456', '0044 7911 123456', '+15551234567'] as $foreign) {
            LiteAssert::same([null, 'unsupported_recipient'], SafirPhone::classify($foreign), 'foreign: ' . $foreign);
        }
        LiteAssert::true(SafirPhone::isSafirFormat('989121234567'));
        LiteAssert::false(SafirPhone::isSafirFormat('+989121234567'));
        LiteAssert::false(SafirPhone::isSafirFormat('98912123456'));
    },

    'outcome: every documented Safir error code maps to the agreed handling' => function () {
        $expect = [
            2 => ['unknown', 'safir_2_internal_error'],
            3 => ['retry', 'safir_3_rate_limited'],
            4 => ['failed', 'safir_4_invalid_input'],
            8 => ['failed', 'safir_8_invalid_phone'],
            17 => ['unsupported', 'safir_17_not_bale_user'],
            20 => ['pause', 'safir_20_payment_required'],
            21 => ['pause', 'safir_21_contact_limit'],
        ];
        foreach ($expect as $code => [$kind, $reason]) {
            foreach ([200, 400] as $status) {
                $o = SafirClient::interpret(FakeSafirTransport::error($code, $status));
                LiteAssert::same($kind, $o->kind, "code {$code} / HTTP {$status}");
                LiteAssert::same($reason, $o->reason);
                LiteAssert::same($code, $o->code);
                LiteAssert::same(null, $o->messageId);
            }
        }
        $undocumented = SafirClient::interpret(FakeSafirTransport::error(99));
        LiteAssert::same('unknown', $undocumented->kind, 'an undocumented code is never treated as safe');
    },

    'outcome: HTTP level — accepted only with a message_id and no errors; 401/403 pause; 429 retry; 5xx / malformed / empty unknown; other 4xx failed' => function () {
        $o = SafirClient::interpret(FakeSafirTransport::accepted('523e6875-7c41-491b-8460-04b33039d7fc'));
        LiteAssert::same('accepted', $o->kind);
        LiteAssert::same('523e6875-7c41-491b-8460-04b33039d7fc', $o->messageId);
        LiteAssert::same('accepted', SafirClient::interpret(new SafirHttpResponse(200, '{"message_id":"BvQjaR.fIKt7kH"}'))->kind, 'OTP-style answer without error_data');
        LiteAssert::same('accepted', SafirClient::interpret(new SafirHttpResponse(200, '{"message_id":"x","error_data":[]}'))->kind);

        foreach ([401, 403] as $s) {
            $p = SafirClient::interpret(new SafirHttpResponse($s, '{"detail":"bad key"}'));
            LiteAssert::same('pause', $p->kind);
            LiteAssert::same('credentials_rejected', $p->reason);
        }
        LiteAssert::same('retry', SafirClient::interpret(new SafirHttpResponse(429, ''))->kind);
        foreach ([500, 502, 503, 504] as $s) {
            LiteAssert::same('unknown', SafirClient::interpret(new SafirHttpResponse($s, '{"message_id":"x"}'))->kind, "HTTP {$s}");
        }
        foreach (['', 'not json', '{"message_id":""}', '{"message_id":123}', '[]', '{"ok":true}', str_repeat('x', CurlSafirTransport::MAX_BODY + 1)] as $body) {
            LiteAssert::same('unknown', SafirClient::interpret(new SafirHttpResponse(200, $body))->kind, 'malformed 200: ' . substr($body, 0, 20));
        }
        LiteAssert::same('failed', SafirClient::interpret(new SafirHttpResponse(400, '{"detail":"bad"}'))->kind);
        LiteAssert::same('failed', SafirClient::interpret(new SafirHttpResponse(404, ''))->kind);
    },

    'outcome: connection failed before sending → retry; lost after sending (timeout) → unknown' => function () {
        LiteAssert::same('retry', SafirClient::interpret(SafirHttpResponse::notSent(7))->kind);
        LiteAssert::same('connection_failed', SafirClient::interpret(SafirHttpResponse::notSent(6))->reason);
        LiteAssert::same('unknown', SafirClient::interpret(SafirHttpResponse::lost(28))->kind);
        LiteAssert::same('unknown', SafirClient::interpret(SafirHttpResponse::lost(56))->kind);
        // The curl transport refuses anything but https without sending.
        $r = (new CurlSafirTransport())->post('http://safir.bale.ai/api/v3/send_message', [], '{}');
        LiteAssert::false($r->requestSent);
    },

    'outcome labels never carry the number, the text, the key or Safir\'s free-text description' => function () use ($persian) {
        $t = new FakeSafirTransport(fn () => FakeSafirTransport::error(8, 200, '989121234567'));
        $o = (new SafirClient($t))->sendText('SECRET-KEY-9999', 5, '989121234567', $persian, 'crm-1');
        $label = $o->label();
        LiteAssert::same('Safir: safir_8_invalid_phone (HTTP 200)', $label);
        foreach (['989121234567', 'SECRET', 'desc for', 'سلام'] as $leak) {
            LiteAssert::notContains($leak, $label);
        }
    },

    'channel contract: Bale is a phone-text, recoverable channel; SMS and email keep their built-in behaviour' => function () {
        LiteAssert::true(is_subclass_of(BaleChannel::class, PhoneTextChannel::class));
        LiteAssert::true(is_subclass_of(BaleChannel::class, RecoverableChannel::class));
        foreach ([SmsChannel::class, EmailChannel::class] as $builtIn) {
            LiteAssert::false(is_subclass_of($builtIn, PhoneTextChannel::class), $builtIn);
            LiteAssert::false(is_subclass_of($builtIn, RecoverableChannel::class), $builtIn);
        }
        LiteAssert::same('crm-1000000000000042', BaleChannel::requestId(1_000_000_000_000_042));
        LiteAssert::true(strlen(BaleChannel::requestId(PHP_INT_MAX)) <= 64);
        // DispatchResult: the new optional fields default to off (existing channels unchanged).
        $r = new DispatchResult(false, 'x', null, 'e', true, 5, false);
        LiteAssert::false($r->stopBatch);
        LiteAssert::same(null, $r->pauseReason);
        LiteAssert::same(null, $r->skipReason);
        // Regression: SMS/email normalization unchanged.
        LiteAssert::same('09121234567', AudienceResolver::normalize('sms', '+989121234567'));
        LiteAssert::same('a@b.co', AudienceResolver::normalize('email', ' A@B.co '));
        LiteAssert::same(['sms', 'email'], ChannelRegistry::BUILT_IN);
    },
];
