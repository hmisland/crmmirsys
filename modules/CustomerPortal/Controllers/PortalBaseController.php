<?php

declare(strict_types=1);

namespace Modules\CustomerPortal\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Shared by the customer-facing controllers: the organization's language,
 * the customer layout, and response headers for pages with personal data
 * (never cached, never framed, no referrer, not indexed).
 */
abstract class PortalBaseController extends BaseController
{
    public function __construct(TemplateEngine $templates, protected readonly string $langPath)
    {
        parent::__construct($templates);
    }

    /** Customer pages speak the organization's default language. */
    protected function useLocale(array $organization): void
    {
        $locale = (string) ($organization['default_language'] ?? '');
        if (in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
            Locale::set(new Translator($locale, $this->langPath));
        }
    }

    protected function page(string $view, array $data, array $organization, bool $signedIn, int $status = 200): Response
    {
        $this->useLocale($organization);

        return $this->secure($this->viewWithLayout($view, 'customer_layout', $data + [
            'organizationName' => (string) $organization['name'],
            'customerSignedIn' => $signedIn,
        ], $status));
    }

    protected function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}
