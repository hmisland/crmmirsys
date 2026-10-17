<?php

declare(strict_types=1);

/**
 * Marketing — provider adapters and pure helpers, no database, no
 * network: every HTTP call goes to FakeHttpClient.
 *  - Sender / Brevo: auth headers, request bodies, response parsing,
 *    authentication failures, validation errors, rate limits (429 +
 *    Retry-After), outages (5xx), timeouts (ambiguous), connect errors.
 *  - Brevo webhook payload parsing; Kavenegar delivery-status parsing.
 *  - Personalization, unsubscribe-token signing, derived keys.
 */

use App\Infrastructure\Config\Config;
use App\Infrastructure\Config\EnvLoader;
use App\Infrastructure\Email\BrevoEmailProvider;
use App\Infrastructure\Email\BrevoWebhookParser;
use App\Infrastructure\Email\EmailMessage;
use App\Infrastructure\Email\SenderNetEmailProvider;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Security\SecretBox;
use App\Infrastructure\Sms\KavenegarSmsSender;
use Modules\Marketing\Services\Personalizer;
use Modules\Marketing\Services\UnsubscribeLinks;
use Tests\Lite\Fixtures\FakeHttpClient;

require_once __DIR__ . '/Fixtures/MarketingFakes.php';

$root = dirname(__DIR__, 2);
if (!class_exists(Config::class, false) || Config::get('app.name') === null) {
    EnvLoader::load($root . '/.env');
    Config::bootstrap($root . '/config');
}

$message = fn (array $over = []) => new EmailMessage(
    $over['to'] ?? 'client@example.test', 'Client', 'news@example.test', 'Shop', 'Hello', '<p>Hi</p>', 'Hi',
    $over['reply'] ?? 'reply@example.test', ['List-Unsubscribe' => '<https://crm.test/m/unsubscribe/x>'], ['marketing'],
);

return [
    // --- Sender -------------------------------------------------------------

    'sender: send uses Bearer auth, POST message/send and the documented body; emailId is the reference' => function () use ($message) {
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(200, ['success' => true, 'message' => 'Email sent', 'emailId' => 'ep2W4y-abc']));
        $result = (new SenderNetEmailProvider('tok-SECRET-123', $http))->send($message());

        LiteAssert::true($result->success);
        LiteAssert::same('ep2W4y-abc', $result->messageId);
        $req = $http->requests[0];
        LiteAssert::same('POST', $req['method']);
        LiteAssert::same('https://api.sender.net/v2/message/send', $req['url']);
        LiteAssert::same('Bearer tok-SECRET-123', $req['headers']['Authorization']);
        $body = json_decode((string) $req['body'], true);
        LiteAssert::same(['email' => 'news@example.test', 'name' => 'Shop'], $body['from']);
        LiteAssert::same(['email' => 'client@example.test', 'name' => 'Client'], $body['to'], 'exactly one recipient object');
        LiteAssert::same('<p>Hi</p>', $body['html']);
        LiteAssert::same('Hi', $body['text']);
        LiteAssert::false(isset($body['reply_to']), 'no undocumented reply-to field sent');
        LiteAssert::false((new SenderNetEmailProvider('x', $http))->capabilities()['reply_to'], 'reply-to reported as unsupported');
    },

    'sender: 2xx without success=true is NOT accepted' => function () use ($message) {
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(200, ['success' => false, 'message' => 'You must activate workflow first']));
        $result = (new SenderNetEmailProvider('t', $http))->send($message());
        LiteAssert::false($result->success);
        LiteAssert::contains('activate workflow', (string) $result->errorMessage);
    },

    'sender: 422 errors are reported field by field, not retried' => function () use ($message) {
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(422, ['message' => 'The given data was invalid.', 'errors' => ['to.email' => ['Required value, email']]]));
        $result = (new SenderNetEmailProvider('t', $http))->send($message());
        LiteAssert::false($result->success);
        LiteAssert::false($result->retryable);
        LiteAssert::same(422, $result->statusCode);
        LiteAssert::contains('to.email: Required value, email', (string) $result->errorMessage);
    },

    'sender: connection test reads (GET campaigns), 401 fails, and the token never appears in the error' => function () {
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(401, ['message' => 'Unauthenticated.']));
        $result = (new SenderNetEmailProvider('tok-SECRET-123', $http))->testConnection('news@example.test');
        LiteAssert::false($result->ok);
        LiteAssert::same('GET', $http->requests[0]['method']);
        LiteAssert::contains('/v2/campaigns', $http->requests[0]['url']);
        LiteAssert::notContains('tok-SECRET-123', (string) $result->error);

        $ok = (new SenderNetEmailProvider('t', new FakeHttpClient(fn () => FakeHttpClient::json(200, ['data' => []]))))->testConnection('a@b.test');
        LiteAssert::true($ok->ok);
        LiteAssert::same(null, $ok->senderVerified, 'Sender cannot verify the sender via API → unknown, not true');
        LiteAssert::contains('email_providers.warning_sender_unverifiable', implode(',', $ok->warnings));
    },

    'sender: 429 is retryable with Retry-After; 5xx retryable; timeout ambiguous; connect error retryable' => function () use ($message) {
        $r429 = (new SenderNetEmailProvider('t', new FakeHttpClient(fn () => FakeHttpClient::json(429, ['message' => 'Too many'], ['retry-after' => '42']))))->send($message());
        LiteAssert::true($r429->retryable);
        LiteAssert::same(42, $r429->retryAfterSeconds);
        LiteAssert::false($r429->ambiguous);

        $r503 = (new SenderNetEmailProvider('t', new FakeHttpClient(fn () => FakeHttpClient::json(503, ['message' => 'down']))))->send($message());
        LiteAssert::true($r503->retryable);

        $timeout = (new SenderNetEmailProvider('t', new FakeHttpClient(fn () => new HttpResponse(0, '', [], 'Operation timed out', false, true))))->send($message());
        LiteAssert::false($timeout->retryable, 'a timeout may have been processed — never auto-retried');
        LiteAssert::true($timeout->ambiguous);

        $refused = (new SenderNetEmailProvider('t', new FakeHttpClient(fn () => new HttpResponse(0, '', [], 'Connection refused', true, false))))->send($message());
        LiteAssert::true($refused->retryable);
        LiteAssert::false($refused->ambiguous);
    },

    // --- Brevo --------------------------------------------------------------

    'brevo: send uses api-key header, POST smtp/email, one "to" entry, replyTo, headers, tags; messageId parsed' => function () use ($message) {
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(201, ['messageId' => '<201798300811.5787683@relay.domain.com>']));
        $result = (new BrevoEmailProvider('xkeysib-SECRET', $http))->send($message());

        LiteAssert::true($result->success);
        LiteAssert::same('<201798300811.5787683@relay.domain.com>', $result->messageId);
        $req = $http->requests[0];
        LiteAssert::same('https://api.brevo.com/v3/smtp/email', $req['url']);
        LiteAssert::same('xkeysib-SECRET', $req['headers']['api-key']);
        LiteAssert::false(isset($req['headers']['Authorization']));
        $body = json_decode((string) $req['body'], true);
        LiteAssert::same(1, count($body['to']), 'never more than one visible recipient');
        LiteAssert::same('client@example.test', $body['to'][0]['email']);
        LiteAssert::same(['email' => 'reply@example.test'], $body['replyTo']);
        LiteAssert::same('<p>Hi</p>', $body['htmlContent']);
        LiteAssert::same('Hi', $body['textContent']);
        LiteAssert::same('<https://crm.test/m/unsubscribe/x>', $body['headers']['List-Unsubscribe']);
        LiteAssert::same(['marketing'], $body['tags']);
    },

    'brevo: connection test = GET account + GET senders; active sender → verified, inactive → warning, bad key → error' => function () {
        $senders = ['senders' => [['email' => 'news@example.test', 'active' => true], ['email' => 'other@example.test', 'active' => false]]];
        $http = new FakeHttpClient(fn ($m, $url) => str_ends_with($url, '/account')
            ? FakeHttpClient::json(200, ['email' => 'owner@example.test', 'companyName' => 'Acme', 'plan' => [['type' => 'free']]])
            : FakeHttpClient::json(200, $senders));

        $ok = (new BrevoEmailProvider('k', $http))->testConnection('news@example.test');
        LiteAssert::true($ok->ok);
        LiteAssert::true($ok->senderVerified);
        LiteAssert::same('free', $ok->account['plan']);
        LiteAssert::contains('email_providers.warning_domain_auth', implode(',', $ok->warnings), 'domain auth is never assumed');

        $inactive = (new BrevoEmailProvider('k', $http))->testConnection('other@example.test');
        LiteAssert::true($inactive->ok, 'authentication works…');
        LiteAssert::false($inactive->senderVerified, '…but the sender is not verified');
        LiteAssert::contains('email_providers.warning_sender_not_active', implode(',', $inactive->warnings));

        $bad = (new BrevoEmailProvider('xkeysib-SECRET', new FakeHttpClient(fn () => FakeHttpClient::json(401, ['code' => 'unauthorized', 'message' => 'Key not found']))))->testConnection('a@b.test');
        LiteAssert::false($bad->ok);
        LiteAssert::contains('Key not found', (string) $bad->error);
        LiteAssert::notContains('xkeysib-SECRET', (string) $bad->error);
    },

    'brevo: webhook registration sends transactional events with bearer auth' => function () {
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(201, ['id' => 77]));
        $result = (new BrevoEmailProvider('k', $http))->registerWebhook('https://crm.test/webhooks/email/brevo/abc', 'secret-token');
        LiteAssert::true($result->ok);
        $body = json_decode((string) $http->requests[0]['body'], true);
        LiteAssert::same('https://api.brevo.com/v3/webhooks', $http->requests[0]['url']);
        LiteAssert::same('transactional', $body['type']);
        LiteAssert::same(['type' => 'bearer', 'token' => 'secret-token'], $body['auth']);
        LiteAssert::true(in_array('hardBounce', $body['events'], true) && in_array('delivered', $body['events'], true) && in_array('unsubscribed', $body['events'], true));
    },

    'brevo: 429 uses x-sib-ratelimit-reset; 500 retryable' => function () use ($message) {
        $r = (new BrevoEmailProvider('k', new FakeHttpClient(fn () => FakeHttpClient::json(429, ['message' => 'rate'], ['x-sib-ratelimit-reset' => '7']))))->send($message());
        LiteAssert::true($r->retryable);
        LiteAssert::same(7, $r->retryAfterSeconds);
        LiteAssert::true((new BrevoEmailProvider('k', new FakeHttpClient(fn () => FakeHttpClient::json(500, []))))->send($message())->retryable);
    },

    'brevo webhook parser: maps events, handles batches, drops untracked events, stable keys' => function () {
        $one = json_encode(['event' => 'delivered', 'email' => 'A@Example.test', 'id' => 1, 'message-id' => '<m1@x>', 'ts_event' => 1700000000]);
        $events = BrevoWebhookParser::parse((string) $one);
        LiteAssert::same(1, count($events));
        LiteAssert::same('delivered', $events[0]['type']);
        LiteAssert::same('<m1@x>', $events[0]['message_id']);
        LiteAssert::same('a@example.test', $events[0]['email']);
        LiteAssert::same('2023-11-14 22:13:20', $events[0]['occurred_at']);
        LiteAssert::same($events[0]['key'], BrevoWebhookParser::parse((string) $one)[0]['key'], 'replay → same idempotency key');

        $batch = BrevoWebhookParser::parse((string) json_encode([
            ['event' => 'hard_bounce', 'message-id' => '<m2@x>', 'reason' => 'mailbox unknown', 'ts_event' => 1],
            ['event' => 'opened', 'message-id' => '<m3@x>'],
            ['event' => 'spam', 'message-id' => '<m4@x>'],
            ['event' => 'unsubscribed', 'message-id' => ''],
        ]));
        LiteAssert::same(['hard_bounce', 'complaint'], array_column($batch, 'type'));
        LiteAssert::same('mailbox unknown', $batch[0]['reason']);
        LiteAssert::same([], BrevoWebhookParser::parse('not json'));
    },

    // --- Kavenegar (backward compatible additions) ---------------------------

    'kavenegar: status codes map to delivery states (10 delivered, 11 undelivered, 14 blocked, 100 unknown)' => function () {
        $parsed = KavenegarSmsSender::parseStatusEntries([
            ['messageid' => 11, 'status' => 10, 'statustext' => 'رسیده به گیرنده'],
            ['messageid' => 12, 'status' => 11],
            ['messageid' => 13, 'status' => 14],
            ['messageid' => 14, 'status' => 4],
            ['messageid' => 15, 'status' => 100],
            ['status' => 10],
        ]);
        LiteAssert::same('delivered', $parsed['11']['state']);
        LiteAssert::same('undelivered', $parsed['12']['state']);
        LiteAssert::same('blocked', $parsed['13']['state']);
        LiteAssert::same('sent', $parsed['14']['state'], 'sent to carrier is not delivered');
        LiteAssert::same('unknown', $parsed['15']['state']);
        LiteAssert::same(5, count($parsed));
    },

    'kavenegar: existing interface unchanged — the sender still implements SmsSenderInterface with the same methods' => function () {
        $ref = new \ReflectionClass(\App\Infrastructure\Sms\SmsSenderInterface::class);
        LiteAssert::same(['name', 'isConfigured', 'send', 'sendTemplate', 'accountInfo'], array_map(fn ($m) => $m->getName(), $ref->getMethods()));
        LiteAssert::true((new KavenegarSmsSender('k', '1000')) instanceof \App\Infrastructure\Sms\SmsSenderInterface);
        LiteAssert::same(3, (new \ReflectionMethod(KavenegarSmsSender::class, 'send'))->getNumberOfParameters());
    },

    // --- Helpers -------------------------------------------------------------

    'personalizer: variables replaced, HTML escaped, unknown variables detected' => function () {
        $vars = Personalizer::varsFor(['first_name' => 'Sara', 'last_name' => '<b>K</b>', 'company_name' => 'Acme & Co'], 'Org');
        LiteAssert::same('Hi Sara <b>K</b> from Acme & Co', Personalizer::render('Hi {{first_name}} {{ last_name }} from {{company}}', $vars));
        LiteAssert::same('Hi Sara &lt;b&gt;K&lt;/b&gt; Acme &amp; Co', Personalizer::render('Hi {{first_name}} {{last_name}} {{company}}', $vars, true));
        LiteAssert::same(['coupon'], Personalizer::unknownVariables('Hello {{first_name}} {{coupon}}'));
        LiteAssert::same([], Personalizer::unknownVariables('Hello {{FIRST_NAME}}'));
    },

    'unsubscribe tokens: round-trip, tamper and cross-organization rejection' => function () {
        $token = UnsubscribeLinks::token(12, 3456);
        LiteAssert::same([12, 3456], UnsubscribeLinks::parse($token));
        [$payload, $mac] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode('13.3456'), '+/', '-_'), '=') . '.' . $mac;
        LiteAssert::same(null, UnsubscribeLinks::parse($forged), 'another organization id with the same MAC is rejected');
        LiteAssert::same(null, UnsubscribeLinks::parse($payload . '.AAAA'));
        LiteAssert::same(null, UnsubscribeLinks::parse('garbage'));
        LiteAssert::contains('/m/unsubscribe/', (new UnsubscribeLinks('https://crm.test'))->url(1, 2));
    },

    'secret box: derived keys differ per purpose and from each other; encryption round-trips without plaintext' => function () {
        LiteAssert::false(SecretBox::derivedKey('a') === SecretBox::derivedKey('b'));
        $cipher = SecretBox::encrypt('xkeysib-SECRET-VALUE');
        LiteAssert::notContains('xkeysib', $cipher);
        LiteAssert::same('xkeysib-SECRET-VALUE', SecretBox::decrypt($cipher));
    },
];
