<?php

declare(strict_types=1);

namespace Modules\CustomerPortal;

use App\Core\Middleware\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\OrganizationEntitlements;

/**
 * Customer pages (/customer/...): only for a signed-in CUSTOMER. Every
 * request re-checks that the organization is active and still holds the
 * Customer Portal module and that the contact is still active; otherwise
 * the customer is signed out. Staff sessions play no part here: the
 * customer comes from CustomerSession, never from CurrentUser.
 *
 * Sets the request attribute "customer" = [organization (id, name, slug,
 * default_language), contact_id] — the ONLY source of who is asking that
 * customer pages use.
 */
final class CustomerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CustomerPortalRepository $repository,
        private readonly OrganizationEntitlements $entitlements,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $session = CustomerSession::customer();
        $organization = $session !== null ? $this->repository->organization($session['organization_id']) : null;
        if ($session === null || $organization === null
            || !$this->entitlements->organizationHas((int) $organization['id'], CustomerPortalModule::KEY)
            || $this->repository->activeContact((int) $organization['id'], $session['contact_id']) === null) {
            $slug = $session['slug'] ?? CustomerSession::lastSlug();
            if ($session !== null) {
                CustomerSession::logout();
            }

            return Response::redirect($slug !== null ? '/customer/login/' . rawurlencode($slug) : '/customer/login');
        }
        $request->setAttribute('customer', ['organization' => $organization, 'contact_id' => $session['contact_id']]);

        return $next($request);
    }
}
