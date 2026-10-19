<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\PublicLinks\PublicLink;
use App\Infrastructure\PublicLinks\PublicLinkRegistry;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * GET/POST /p/{token} — the public customer-experience entry point (no
 * login). Resolves the token, then hands over to the module handler
 * registered for the link's purpose, inside the link's organization and
 * in the link's language (falling back to the organization's default
 * language, so Persian/RTL works for a customer without any session).
 *
 * Any problem — unknown, malformed, expired, revoked or used-up token,
 * a handler whose module the organization no longer holds — gives the
 * same neutral "link not available" page (HTTP 404), so the response
 * never tells an attacker which tokens exist. POSTs are rate-limited
 * per client address. Responses are never cached and never send the
 * token on in a Referer header.
 */
final class PublicLinkController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly PublicLinkService $links,
        private readonly PublicLinkRegistry $handlers,
        private readonly OrganizationEntitlements $entitlements,
        private readonly string $langPath,
        private readonly ActionRateLimiter $limiter = new ActionRateLimiter(),
    ) {
        parent::__construct($templates);
    }

    public function show(Request $request): Response
    {
        return $this->dispatch($request, false);
    }

    public function submit(Request $request): Response
    {
        $ip = (string) ($request->server('REMOTE_ADDR') ?? 'unknown');
        if (!$this->limiter->attempt('public_link.submit', [['subject' => 'ip:' . $ip, 'max' => 20, 'window' => 600]])) {
            return $this->secure($this->viewWithLayout('public/unavailable', 'public_layout', ['reason' => 'rate_limited'], 429));
        }

        return $this->dispatch($request, true);
    }

    private function dispatch(Request $request, bool $isSubmit): Response
    {
        $link = $this->links->resolve((string) $request->attribute('token', ''));
        $handler = $link !== null ? $this->handlers->get($link->purpose) : null;
        if ($link === null || $handler === null
            || ($handler->moduleKey() !== null && !$this->entitlements->organizationHas($link->organizationId, $handler->moduleKey()))) {
            return $this->unavailable();
        }

        $this->useLocale($link);

        $response = OrganizationContext::runAs($link->organizationId, fn () => $isSubmit ? $handler->submit($link, $request) : $handler->show($link, $request));

        return $this->secure($response);
    }

    private function useLocale(PublicLink $link): void
    {
        $locale = $link->locale;
        if ($locale === null) {
            $organization = (new OrganizationRepository())->findById($link->organizationId);
            $locale = $organization['default_language'] ?? null;
        }
        if (is_string($locale) && in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
            Locale::set(new Translator($locale, $this->langPath));
        }
    }

    private function unavailable(): Response
    {
        return $this->secure($this->viewWithLayout('public/unavailable', 'public_layout', ['reason' => 'invalid'], 404));
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}
