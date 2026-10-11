<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Services;

use App\Core\Container;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;

/**
 * The one place Client Records talks to other optional modules
 * (Finance, Follow-ups). Nothing here owns data or business rules — it
 * only calls those modules' own services, and only when all three hold:
 *
 *   1. the module is installed (its service is in the container),
 *   2. the module is entitled for this user (ModuleAccessResolver —
 *      the same chain ModuleMiddleware enforces),
 *   3. the user has that module's own permission, which the called
 *      service re-checks itself anyway.
 *
 * Services are resolved lazily from the container at call time: module
 * register() order is filesystem order, so Finance/Follow-ups may not
 * have bound theirs yet when this module is constructed. Same "has()
 * then make()" pattern the Dashboard uses.
 */
final class ClientIntegrations
{
    private const FOLLOW_UP_SERVICE = \Modules\Followups\Services\FollowUpService::class;
    private const INVOICE_SERVICE = \Modules\Finance\Services\InvoiceService::class;
    private const PAYMENT_SERVICE = \Modules\Finance\Services\PaymentService::class;
    private const PRODUCT_SERVICE = \Modules\Finance\Services\ProductService::class;

    /** @var array<string, bool> per-request memo of module entitlement */
    private array $moduleCache = [];

    public function __construct(
        private readonly Container $container,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    // ---- Follow-ups ------------------------------------------------

    public function followUpsAvailable(array $user): bool
    {
        return $this->serviceAvailable($user, 'followups', self::FOLLOW_UP_SERVICE)
            && $this->authorization->can($user, Permission::FOLLOWUPS_VIEW);
    }

    public function canCreateFollowUp(array $user): bool
    {
        return $this->followUpsAvailable($user) && $this->authorization->can($user, Permission::FOLLOWUPS_CREATE);
    }

    /**
     * A client's follow-ups = follow-ups related to its contact.
     * FollowUpService::forRelated() leaves authorization to the caller
     * (it already checked the parent), so the caller's followups.view
     * scope is applied here: OWN only sees follow-ups they own or are
     * assigned to.
     */
    public function followUpsForContact(array $user, int $contactId): array
    {
        if (!$this->followUpsAvailable($user)) {
            return [];
        }

        $rows = $this->container->make(self::FOLLOW_UP_SERVICE)->forRelated('contact', $contactId);
        $scope = $this->authorization->scopeFor($user, Permission::FOLLOWUPS_VIEW);

        if ($scope === Scope::OWN) {
            $rows = array_values(array_filter($rows, fn ($f) => $f['owner_id'] === $user['id'] || $f['assignee_user_id'] === $user['id']));
        }

        return $rows;
    }

    /** Creates the follow-up through the Follow-ups module (its own validation, RBAC and timeline). */
    public function createFollowUp(array $user, int $contactId, array $input): ?array
    {
        if (!$this->canCreateFollowUp($user)) {
            return null;
        }

        $input['related_type'] = 'contact';
        $input['related_id'] = $contactId;

        return $this->container->make(self::FOLLOW_UP_SERVICE)->create($user, $input);
    }

    // ---- Finance ---------------------------------------------------

    public function invoicesAvailable(array $user): bool
    {
        return $this->serviceAvailable($user, 'finance', self::INVOICE_SERVICE)
            && $this->authorization->can($user, Permission::INVOICES_VIEW);
    }

    public function paymentsAvailable(array $user): bool
    {
        return $this->invoicesAvailable($user)
            && $this->container->has(self::PAYMENT_SERVICE)
            && $this->authorization->can($user, Permission::PAYMENTS_VIEW);
    }

    public function productsAvailable(array $user): bool
    {
        return $this->serviceAvailable($user, 'finance', self::PRODUCT_SERVICE)
            && $this->authorization->can($user, Permission::PRODUCTS_VIEW);
    }

    /** The contact's invoices, already narrowed to what invoices.view's scope lets this user see. */
    public function invoicesForContact(array $user, int $contactId): array
    {
        if (!$this->invoicesAvailable($user)) {
            return [];
        }

        try {
            return $this->container->make(self::INVOICE_SERVICE)->list($user, ['contact_id' => $contactId]);
        } catch (AuthorizationException) {
            return [];
        }
    }

    /** @return array<int, array> invoice id => its payments (empty for invoices the user can't see payments of) */
    public function paymentsForInvoices(array $user, array $invoices): array
    {
        if (!$this->paymentsAvailable($user)) {
            return [];
        }

        $service = $this->container->make(self::PAYMENT_SERVICE);
        $result = [];

        foreach ($invoices as $invoice) {
            try {
                $result[(int) $invoice['id']] = $service->listFor($user, (int) $invoice['id']);
            } catch (HttpException) {
                $result[(int) $invoice['id']] = [];
            }
        }

        return $result;
    }

    /**
     * Resolves an invoice id from input for linking: it must be visible
     * to this user AND belong to this client's contact — otherwise the
     * link is refused (no linking another client's invoice by id).
     */
    public function invoiceForContact(array $user, int $invoiceId, int $contactId): ?array
    {
        foreach ($this->invoicesForContact($user, $contactId) as $invoice) {
            if ((int) $invoice['id'] === $invoiceId) {
                return $invoice;
            }
        }

        return null;
    }

    public function activeProducts(array $user): array
    {
        if (!$this->productsAvailable($user)) {
            return [];
        }

        try {
            return $this->container->make(self::PRODUCT_SERVICE)->list($user, ['is_active' => true]);
        } catch (AuthorizationException) {
            return [];
        }
    }

    public function findProduct(array $user, int $productId): ?array
    {
        if (!$this->productsAvailable($user)) {
            return null;
        }

        try {
            return $this->container->make(self::PRODUCT_SERVICE)->find($user, $productId);
        } catch (HttpException) {
            return null;
        }
    }

    // ---- Shared ----------------------------------------------------

    public function moduleEnabled(array $user, string $moduleKey): bool
    {
        if (!array_key_exists($moduleKey, $this->moduleCache)) {
            $this->moduleCache[$moduleKey] = (new ModuleAccessResolver(
                new ModuleRepository(),
                new OrganizationModuleRepository(),
                new UserModuleOverrideRepository(),
            ))->resolvedForUser($moduleKey, $user);
        }

        return $this->moduleCache[$moduleKey];
    }

    private function serviceAvailable(array $user, string $moduleKey, string $serviceClass): bool
    {
        return $this->container->has($serviceClass) && $this->moduleEnabled($user, $moduleKey);
    }
}
