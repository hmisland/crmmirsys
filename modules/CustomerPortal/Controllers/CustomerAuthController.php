<?php

declare(strict_types=1);

namespace Modules\CustomerPortal\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerPortal\CustomerSession;
use Modules\CustomerPortal\Services\CustomerAuthService;

use function App\Infrastructure\Theme\t;

/**
 * Customer sign-in (/customer/login/{slug}/...): email or mobile → code;
 * or national ID → pick a masked destination → code. The organization
 * comes from the address; an unknown or portal-less organization gives a
 * 404 (no portal there). Every POST is CSRF-protected.
 */
final class CustomerAuthController extends PortalBaseController
{
    public function __construct(TemplateEngine $templates, string $langPath, private readonly CustomerAuthService $auth)
    {
        parent::__construct($templates, $langPath);
    }

    /** /customer/login without an organization: explain where to sign in. */
    public function neutral(Request $request): Response
    {
        $slug = CustomerSession::lastSlug();
        if ($slug !== null && $this->auth->organization($slug) !== null) {
            return $this->redirect('/customer/login/' . rawurlencode($slug));
        }

        return $this->secure($this->viewWithLayout('customer/no-organization', 'customer_layout', ['organizationName' => '', 'customerSignedIn' => false]));
    }

    public function showLogin(Request $request): Response
    {
        $organization = $this->organization($request);
        if ((CustomerSession::customer()['organization_id'] ?? null) === (int) $organization['id']) {
            return $this->redirect('/customer');
        }

        return $this->page('customer/login', ['slug' => $organization['slug'], 'errors' => [], 'old' => ''], $organization, false);
    }

    public function login(Request $request): Response
    {
        $organization = $this->organization($request);
        $identifier = (string) $request->input('identifier', '');
        try {
            $this->auth->requestCode($organization, $identifier, $this->ip($request));
        } catch (ValidationException $e) {
            return $this->page('customer/login', ['slug' => $organization['slug'], 'errors' => $e->errors, 'old' => mb_substr($identifier, 0, 190)], $organization, false, 422);
        }
        Session::setFlash('success', t('customer_portal.code_sent_if_registered'));

        return $this->redirect($this->base($organization) . '/verify');
    }

    public function showNationalId(Request $request): Response
    {
        $organization = $this->organization($request);

        return $this->page('customer/national-id', ['slug' => $organization['slug'], 'errors' => [], 'notFound' => false, 'options' => []], $organization, false);
    }

    public function nationalId(Request $request): Response
    {
        $organization = $this->organization($request);
        try {
            $options = $this->auth->lookupNationalId($organization, (string) $request->input('national_id', ''), $this->ip($request));
        } catch (ValidationException $e) {
            return $this->page('customer/national-id', ['slug' => $organization['slug'], 'errors' => $e->errors, 'notFound' => false, 'options' => []], $organization, false, 422);
        }

        return $this->page('customer/national-id', ['slug' => $organization['slug'], 'errors' => [], 'notFound' => $options === [], 'options' => $options], $organization, false);
    }

    public function choose(Request $request): Response
    {
        $organization = $this->organization($request);
        if (!$this->auth->chooseDestination($organization, (int) $request->input('destination', -1), $this->ip($request))) {
            Session::setFlash('error', t('customer_portal.error.step_expired'));

            return $this->redirect($this->base($organization) . '/national-id');
        }
        Session::setFlash('success', t('customer_portal.code_sent'));

        return $this->redirect($this->base($organization) . '/verify');
    }

    public function showVerify(Request $request): Response
    {
        $organization = $this->organization($request);
        if (!$this->auth->hasPending($organization)) {
            return $this->redirect($this->base($organization));
        }

        return $this->page('customer/verify', ['slug' => $organization['slug']], $organization, false);
    }

    public function verify(Request $request): Response
    {
        $organization = $this->organization($request);
        try {
            $result = $this->auth->verify($organization, (string) $request->input('code', ''), $this->ip($request));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'customer_portal.error.too_many')));

            return $this->redirect($this->base($organization) . '/verify');
        }
        if ($result === 'ok') {
            return $this->redirect('/customer');
        }
        Session::setFlash('error', t($result === 'locked' ? 'customer_portal.error.code_locked' : 'customer_portal.error.code_invalid'));

        return $this->redirect($this->base($organization) . '/verify');
    }

    public function resend(Request $request): Response
    {
        $organization = $this->organization($request);
        $wait = $this->auth->resend($organization, $this->ip($request));
        if ($wait === null) {
            return $this->redirect($this->base($organization));
        }
        Session::setFlash($wait > 0 ? 'error' : 'success', $wait > 0 ? t('customer_portal.error.wait', ['seconds' => (string) $wait]) : t('customer_portal.code_resent'));

        return $this->redirect($this->base($organization) . '/verify');
    }

    public function logout(Request $request): Response
    {
        $slug = CustomerSession::customer()['slug'] ?? CustomerSession::lastSlug();
        CustomerSession::logout();

        return $this->redirect($slug !== null ? '/customer/login/' . rawurlencode($slug) : '/customer/login');
    }

    private function organization(Request $request): array
    {
        $organization = $this->auth->organization((string) $request->attribute('slug', ''));
        if ($organization === null) {
            throw new NotFoundException();
        }
        CustomerSession::rememberSlug((string) $organization['slug']);
        $this->useLocale($organization);

        return $organization;
    }

    private function base(array $organization): string
    {
        return '/customer/login/' . rawurlencode((string) $organization['slug']);
    }

    private function ip(Request $request): string
    {
        return (string) ($request->server('REMOTE_ADDR') ?? 'unknown');
    }
}
