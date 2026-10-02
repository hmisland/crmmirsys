<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\ProductRepository;
use Modules\Finance\Services\CreditNoteService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\TaxProfileRepository;

final class InvoiceController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly InvoiceService $invoices,
        private readonly ContactRepository $contacts,
        private readonly CurrencyRepository $currencies,
        private readonly TaxProfileRepository $taxProfiles,
        private readonly ProductRepository $products,
        private readonly AuthorizationEngine $authorization,
        private readonly PaymentService $payments,
        private readonly FinancialProfileRepository $financialProfiles,
        private readonly CreditNoteService $creditNotes,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('finance/invoices/index', 'layout', [
            'invoices' => $this->invoices->list(CurrentUser::user()),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $invoice = $this->invoices->find(CurrentUser::user(), $id);
        $user = CurrentUser::user();
        $totalCredited = $this->creditNotes->totalCreditedFor($id);
        $effectiveTotal = bcsub($invoice['grand_total'], $totalCredited, 8);

        return $this->viewWithLayout('finance/invoices/show', 'layout', [
            'invoice' => $invoice,
            'items' => $this->invoices->itemsFor($id),
            'contact' => $invoice['contact_id'] !== null ? $this->contacts->findById((int) $invoice['contact_id']) : null,
            'canIssue' => $this->authorization->can($user, Permission::INVOICES_ISSUE, $invoice),
            'canCancel' => $this->authorization->can($user, Permission::INVOICES_CANCEL, $invoice),
            'canRecordPayment' => $this->authorization->can($user, Permission::PAYMENTS_CREATE, $invoice),
            'canIssueCreditNote' => $this->authorization->can($user, Permission::CREDIT_NOTES_CREATE, $invoice),
            'payments' => $this->authorization->can($user, Permission::PAYMENTS_VIEW, $invoice) ? $this->payments->listFor($user, $id) : [],
            'creditNotes' => $this->creditNotes->listFor($id),
            'totalCredited' => $totalCredited,
            'effectiveTotal' => $effectiveTotal,
            'paymentStatus' => PaymentService::paymentStatus($invoice, $totalCredited),
            'outstanding' => bcsub($effectiveTotal, $invoice['paid_total'], 8),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->form(null, [], []);
    }

    public function create(Request $request): Response
    {
        try {
            $invoice = $this->invoices->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->form(null, $e->errors, $request->all());
        }

        Session::setFlash('success', 'Invoice created as a draft.');

        return $this->redirect('/finance/invoices/' . $invoice['id']);
    }

    /** The client's priority: direct Proforma -> Invoice, one click, no intermediate stage. */
    public function convertFromProforma(Request $request): Response
    {
        $proformaId = (int) $request->attribute('id');

        try {
            $invoice = $this->invoices->createFromProforma(CurrentUser::user(), $proformaId);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not convert this Proforma.');

            return $this->redirect('/finance/proformas/' . $proformaId);
        }

        Session::setFlash('success', 'Converted to an Invoice draft — still needs an Org Admin to issue it.');

        return $this->redirect('/finance/invoices/' . $invoice['id']);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $invoice = $this->invoices->find(CurrentUser::user(), $id);

        return $this->form($invoice, [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->invoices->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->form($this->invoices->find(CurrentUser::user(), $id), $e->errors, $request->all());
        }

        Session::setFlash('success', 'Invoice updated.');

        return $this->redirect('/finance/invoices/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->invoices->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Draft invoice deleted.');

        return $this->redirect('/finance/invoices');
    }

    /** The approval step — org_admin only, enforced in the service. */
    public function issue(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->invoices->issue(CurrentUser::user(), $id);
            Session::setFlash('success', 'Invoice issued.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not issue this invoice.');
        }

        return $this->redirect('/finance/invoices/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->invoices->cancel(CurrentUser::user(), $id);
            Session::setFlash('success', 'Invoice cancelled.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not cancel this invoice.');
        }

        return $this->redirect('/finance/invoices/' . $id);
    }

    private function form(?array $invoice, array $errors, array $old): Response
    {
        $organizationId = (int) CurrentUser::user()['organization_id'];
        $profile = $this->financialProfiles->find($organizationId);

        return $this->viewWithLayout('finance/invoices/form', 'layout', [
            'invoice' => $invoice,
            'items' => $invoice !== null ? $this->invoices->itemsFor((int) $invoice['id']) : [],
            'contacts' => $this->contacts->search([]),
            'currencies' => $this->currencies->all(),
            'defaultCurrencyId' => $profile['base_currency_id'] ?? null,
            'taxProfiles' => $this->taxProfiles->availableFor($organizationId),
            'products' => $this->products->search(['is_active' => true]),
            'errors' => $errors,
            'old' => $old,
        ], $errors === [] ? 200 : 422);
    }
}
