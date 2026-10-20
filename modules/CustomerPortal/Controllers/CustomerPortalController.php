<?php

declare(strict_types=1);

namespace Modules\CustomerPortal\Controllers;

use App\Core\Container;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerPortal\CustomerRecordsRepository;
use Modules\CustomerSatisfaction\Services\ResponseService;

use function App\Infrastructure\Theme\t;

/**
 * The signed-in customer's pages — READ-ONLY (V1): profile, surveys (the
 * one thing a customer submits is a survey answer), invoices, proformas.
 *
 * Who is asking comes ONLY from the "customer" request attribute set by
 * CustomerAuthMiddleware (organization + contact from the customer
 * session). Every record is read through CustomerRecordsRepository, whose
 * queries filter on that organization AND that contact; an id in the URL
 * of anything else is a 404. Surveys need Customer Satisfaction, invoices
 * and proformas need Finance — a section whose module the organization
 * does not hold is not shown and its pages are 404.
 */
final class CustomerPortalController extends PortalBaseController
{
    private const FINANCE = 'finance';
    private const CX = 'customer_satisfaction';

    public function __construct(
        TemplateEngine $templates,
        string $langPath,
        private readonly CustomerRecordsRepository $records,
        private readonly OrganizationEntitlements $entitlements,
        private readonly Container $container,
    ) {
        parent::__construct($templates, $langPath);
    }

    public function home(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request);
        $profile = $this->records->profile((int) $organization['id'], $contactId);

        return $this->page('customer/home', ['name' => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')), 'sections' => $this->sections($organization)], $organization, true);
    }

    public function profile(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request);
        $profile = $this->records->profile((int) $organization['id'], $contactId) ?? throw new NotFoundException();

        return $this->page('customer/profile', ['profile' => $profile], $organization, true);
    }

    // --- Surveys --------------------------------------------------------------------

    public function surveys(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request, self::CX);

        return $this->page('customer/surveys', ['surveys' => $this->records->surveys((int) $organization['id'], $contactId)], $organization, true);
    }

    public function survey(Request $request): Response
    {
        return $this->surveyPage($request, false);
    }

    public function answerSurvey(Request $request): Response
    {
        return $this->surveyPage($request, true);
    }

    /**
     * The survey is answered through the invitation's own single-use link —
     * looked up by id only AFTER the invitation is proven to be this
     * customer's — so the existing anonymous link and the portal share one
     * answer: whichever is used first consumes the link.
     */
    private function surveyPage(Request $request, bool $submit): Response
    {
        [$organization, $contactId] = $this->customer($request, self::CX);
        $organizationId = (int) $organization['id'];
        $id = (int) $request->attribute('id');
        $invitation = $this->records->surveyInvitation($organizationId, $contactId, $id) ?? throw new NotFoundException();
        if (!$this->container->has(ResponseService::class) || !$this->container->has(PublicLinkService::class)) {
            throw new NotFoundException();
        }
        $link = $invitation['status'] === 'sent' && $invitation['public_link_id'] !== null
            ? $this->container->make(PublicLinkService::class)->resolveById((int) $invitation['public_link_id'], $organizationId)
            : null;
        $responses = $this->container->make(ResponseService::class);
        $context = $link !== null ? OrganizationContext::runAs($organizationId, fn () => $responses->context($link)) : null;
        if ($context === null) {
            return $this->page('customer/survey-closed', ['invitation' => $invitation], $organization, true);
        }
        $data = ['survey' => $context['survey'], 'questions' => $context['questions'], 'token' => '', 'formAction' => '/customer/surveys/' . $id, 'errors' => [], 'old' => []];
        if (!$submit) {
            return $this->page('cx/public-survey', $data, $organization, true);
        }

        $result = OrganizationContext::runAs($organizationId, fn () => $responses->submit($link, $request->all()));
        if ($result['ok']) {
            Session::setFlash('success', t('customer_portal.surveys.thanks'));

            return $this->redirect('/customer/surveys');
        }
        if (isset($result['errors']['_'])) {
            return $this->page('customer/survey-closed', ['invitation' => $invitation], $organization, true);
        }

        return $this->page('cx/public-survey', ['errors' => $result['errors'], 'old' => $request->all()] + $data, $organization, true, 422);
    }

    // --- Invoices and proformas -----------------------------------------------------

    public function invoices(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request, self::FINANCE);
        $pagination = Pagination::fromRequest($request, $this->records->invoiceCount((int) $organization['id'], $contactId));

        return $this->page('customer/invoices', [
            'invoices' => $this->records->invoices((int) $organization['id'], $contactId, $pagination->perPage, $pagination->offset()),
            'pagination' => $pagination,
        ], $organization, true);
    }

    public function invoice(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request, self::FINANCE);
        $invoice = $this->records->invoice((int) $organization['id'], $contactId, (int) $request->attribute('id')) ?? throw new NotFoundException();

        return $this->page('customer/invoice', ['invoice' => $invoice], $organization, true);
    }

    public function proformas(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request, self::FINANCE);
        $pagination = Pagination::fromRequest($request, $this->records->proformaCount((int) $organization['id'], $contactId));

        return $this->page('customer/proformas', [
            'proformas' => $this->records->proformas((int) $organization['id'], $contactId, $pagination->perPage, $pagination->offset()),
            'pagination' => $pagination,
        ], $organization, true);
    }

    public function proforma(Request $request): Response
    {
        [$organization, $contactId] = $this->customer($request, self::FINANCE);
        $proforma = $this->records->proforma((int) $organization['id'], $contactId, (int) $request->attribute('id')) ?? throw new NotFoundException();

        return $this->page('customer/proforma', ['proforma' => $proforma], $organization, true);
    }

    // --- Internals ------------------------------------------------------------------

    /** @return array{0: array, 1: int} the customer's organization and contact id; 404 when $module is not held */
    private function customer(Request $request, ?string $module = null): array
    {
        $customer = $request->attribute('customer');
        if (!is_array($customer)) {
            throw new NotFoundException();
        }
        if ($module !== null && !$this->entitlements->organizationHas((int) $customer['organization']['id'], $module)) {
            throw new NotFoundException();
        }

        return [$customer['organization'], (int) $customer['contact_id']];
    }

    /** @return array<string, bool> which sections this organization offers */
    private function sections(array $organization): array
    {
        $id = (int) $organization['id'];

        return [
            'surveys' => $this->entitlements->organizationHas($id, self::CX) && $this->container->has(ResponseService::class),
            'invoices' => $this->entitlements->organizationHas($id, self::FINANCE),
            'proformas' => $this->entitlements->organizationHas($id, self::FINANCE),
        ];
    }

    /** Pages call this with the customer's layout (the parent's page() sets language and headers). */
    protected function page(string $view, array $data, array $organization, bool $signedIn, int $status = 200): Response
    {
        return parent::page($view, $data + ['sections' => $this->sections($organization)], $organization, $signedIn, $status);
    }
}
