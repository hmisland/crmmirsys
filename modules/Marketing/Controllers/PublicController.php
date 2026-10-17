<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Marketing\Services\SuppressionService;
use Modules\Marketing\Services\UnsubscribeLinks;
use Modules\Marketing\Services\WebhookService;

/**
 * Routes reached without a login:
 *  - /m/unsubscribe/{token}: GET shows a confirmation button, POST
 *    unsubscribes (also RFC 8058 one-click from mail clients). The
 *    signed token is the only authorization; no CSRF token exists for a
 *    mail client, and the HMAC already proves who may unsubscribe.
 *    Marketing 3.1 (F3): this does NOT depend on the organization still
 *    having the Marketing module — a recipient of an earlier campaign
 *    must always be able to opt out. It only needs a valid signature
 *    and the message to exist in the token's own organization; the page
 *    shows nothing about the organization or the contact.
 *  - /webhooks/email/{provider}/{key}: provider delivery events,
 *    authenticated by WebhookService.
 */
final class PublicController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly SuppressionService $suppressions,
        private readonly WebhookService $webhooks,
    ) {
        parent::__construct($templates);
    }

    public function unsubscribeForm(Request $request): Response
    {
        $this->verifiedToken($request);

        return $this->viewWithLayout('marketing/unsubscribe', 'layout', ['token' => (string) $request->attribute('token'), 'done' => false]);
    }

    public function unsubscribe(Request $request): Response
    {
        [$organizationId, $messageId] = $this->verifiedToken($request);
        OrganizationContext::runAs($organizationId, fn () => $this->suppressions->unsubscribeMessage($messageId));

        return $this->viewWithLayout('marketing/unsubscribe', 'layout', ['token' => '', 'done' => true]);
    }

    public function webhook(Request $request): Response
    {
        $raw = (string) (file_get_contents('php://input') ?: '');
        if ($raw === '' && $request->all() !== []) {
            $raw = (string) json_encode($request->all()); // already-decoded JSON body
        }
        $authorization = (string) ($request->header('Authorization') ?? $request->server('REDIRECT_HTTP_AUTHORIZATION') ?? $request->server('HTTP_AUTHORIZATION') ?? '');

        $result = $this->webhooks->handle(
            (string) $request->attribute('provider'),
            (string) $request->attribute('key'),
            $authorization,
            $raw,
        );

        return Response::json(['processed' => $result['processed']], $result['status']);
    }

    /**
     * Valid signature + the message exists in that organization
     * (tenant-scoped lookup). Anything else is a plain 404.
     *
     * @return array{0: int, 1: int}
     */
    private function verifiedToken(Request $request): array
    {
        $parsed = UnsubscribeLinks::parse((string) $request->attribute('token', ''));
        if ($parsed === null) {
            throw new NotFoundException();
        }

        [$organizationId, $messageId] = $parsed;
        if (!OrganizationContext::runAs($organizationId, fn () => $this->suppressions->messageExists($messageId))) {
            throw new NotFoundException();
        }

        return $parsed;
    }
}
