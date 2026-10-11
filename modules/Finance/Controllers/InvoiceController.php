<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Finance\CurrencyPrecision;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\Money;
use Modules\Finance\ProductRepository;
use Modules\Finance\Services\CreditNoteService;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Services\ProjectInvoiceService;
use Modules\Finance\TaxProfileRepository;

use function App\Infrastructure\Theme\t;

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
        private readonly ?ContractBillingService $billing = null,
        private readonly ?TimelineService $timeline = null,
        private readonly UserRepository $users = new UserRepository(),
        private readonly ?ProjectInvoiceService $projectInvoices = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key, '')) ? (string) $request->query($key) : '';
        $filters = [
            'status' => in_array($request->query('status'), ['draft', 'issued', 'cancelled'], true) ? (string) $request->query('status') : '',
            'search' => trim((string) $request->query('q', '')),
            'date_from' => $date('from'),
            'date_to' => $date('to'),
        ];

        // Database-side paging; re-read once if the requested page was past the end.
        $requested = Pagination::fromRequest($request, PHP_INT_MAX);
        $page = $this->invoices->listPage(CurrentUser::user(), $filters, $requested->perPage, $requested->offset());
        $pagination = Pagination::fromRequest($request, $page['total']);
        if ($pagination->offset() !== $requested->offset()) {
            $page = $this->invoices->listPage(CurrentUser::user(), $filters, $pagination->perPage, $pagination->offset());
        }

        return $this->viewWithLayout('finance/invoices/index', 'layout', [
            'invoices' => $page['rows'],
            'rowActions' => $this->invoices->rowPermissions(CurrentUser::user(), $page['rows']),
            'pagination' => $pagination,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $invoice = $this->invoices->find(CurrentUser::user(), $id);
        $user = CurrentUser::user();
        $totalCredited = $this->creditNotes->totalCreditedFor($id);
        $effectiveTotal = bcsub($invoice['grand_total'], $totalCredited, 18); // Stage 5.2 (L3): scale 18 everywhere
        // Patch B: credit-note details (list, numbers, amounts, credited and
        // effective totals) only with credit_notes.view. Outstanding and the
        // payment status still account for every credit note.
        $canViewCreditNotes = $this->authorization->can($user, Permission::CREDIT_NOTES_VIEW, $invoice);

        return $this->viewWithLayout('finance/invoices/show', 'layout', [
            'contractLink' => $this->billing?->linkOf(CurrentUser::user(), 'invoice', $id),
            'invoice' => $invoice,
            'items' => $this->invoices->itemsFor($id),
            'contact' => $invoice['contact_id'] !== null ? $this->contacts->findById((int) $invoice['contact_id']) : null,
            'canIssue' => $this->authorization->can($user, Permission::INVOICES_ISSUE, $invoice),
            'canCancel' => $this->authorization->can($user, Permission::INVOICES_CANCEL, $invoice),
            'canRecordPayment' => $this->authorization->can($user, Permission::PAYMENTS_CREATE, $invoice),
            'canIssueCreditNote' => $this->authorization->can($user, Permission::CREDIT_NOTES_CREATE, $invoice),
            'payments' => $this->authorization->can($user, Permission::PAYMENTS_VIEW, $invoice) ? $this->payments->listFor($user, $id) : [],
            'canViewCreditNotes' => $canViewCreditNotes,
            'creditNotes' => $canViewCreditNotes ? $this->creditNotes->listFor($id) : [],
            'totalCredited' => $canViewCreditNotes ? $totalCredited : null,
            'effectiveTotal' => $canViewCreditNotes ? $effectiveTotal : null,
            // Whether anything is left to credit: a yes/no for the create form, never an amount.
            'canCreditMore' => bccomp($effectiveTotal, '0', 18) > 0,
            'paymentStatus' => PaymentService::paymentStatus($invoice, $totalCredited),
            'outstanding' => bcsub($effectiveTotal, $invoice['paid_total'], 18),
            // Patch A: why an issued invoice can't be cancelled (null = it can), and its cancel history.
            'cancelBlockedReason' => ($blocked = $this->invoices->cancelBlockReason($invoice)) !== null ? t($blocked) : null,
            'cancellations' => $this->cancellations($id),
            // Stage 5.2 (L4): payment events only with payments.view, credit-note events only with credit_notes.view.
            'financialHistory' => $this->financialHistory($id, array_keys(array_filter([
                'payment_recorded' => $this->authorization->can($user, Permission::PAYMENTS_VIEW, $invoice),
                'credit_note_issued' => $canViewCreditNotes,
            ]))),
            // Stage 5.2 (L2/L3): display at the invoice currency's precision; the issue form's
            // final-rounding choice defaults to the organization setting.
            'currencyCode' => CurrencyPrecision::currency((int) $invoice['currency_id'])['code'] ?? null,
            'moneyStep' => $this->moneyStep(Money::decimalsFor((int) $invoice['currency_id'])),
            'roundingDefault' => (int) ($this->financialProfiles->find((int) $user['organization_id'])['invoice_final_rounding'] ?? 0) === 1,
            'roundingPrecision' => Money::finalRoundingDecimalsFor((int) $invoice['currency_id']),
        ]);
    }

    /** The smallest amount a money input accepts at this precision ("1", "0.01", "0.001"). */
    private function moneyStep(int $decimals): string
    {
        return $decimals <= 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
    }

    public function showCreate(Request $request): Response
    {
        // "New invoice" on a contract page (docs/contract-finance-design.md):
        // prefilled from the contract, read through RecordTypeRegistry only.
        // A contract this user can't view → 404, never a prefilled form.
        // "New invoice" from a contract installment (Stage 2): an installment or
        // contract this user can't see → 404; one that can't be billed now (void,
        // already has an active document, contract not active / expired) → back
        // to the contract with the reason.
        if ($request->query('from_installment') !== null) {
            return $this->fromInstallment((int) $request->query('from_installment', 0));
        }
        // "New invoice" from a project (Stage 3-C): a project this user can't see → 404,
        // no projects.edit → 403, a completed / cancelled one → back to it with the reason.
        if ($request->query('from_project') !== null) {
            return $this->fromProject((int) $request->query('from_project', 0));
        }

        $fromContract = (int) $request->query('from_contract', 0);
        if ($fromContract > 0 || $request->query('from_contract') !== null) {
            $draft = $this->billing?->draftFrom(CurrentUser::user(), $fromContract);
            if ($draft === null) {
                throw new NotFoundException();
            }

            return $this->form(null, [], $draft);
        }

        return $this->form(null, [], []);
    }

    public function create(Request $request): Response
    {
        try {
            $input = $request->all();
            $sourceType = (string) ($input['source_type'] ?? '');
            if ($sourceType === ProjectInvoiceService::SOURCE_TYPE) {
                // Stage 3-C: from a project — the project is re-checked by the service, never trusted from the form.
                if ($this->projectInvoices === null) {
                    throw new ValidationException(['source' => [t('finance.project.error.project_unavailable')]]);
                }
                $invoice = $this->projectInvoices->createFromProject(CurrentUser::user(), $input, (int) ($input['source_id'] ?? 0));
            } elseif ($sourceType !== '' || !empty($input['source_id'])) {
                if ($this->billing === null) {
                    throw new ValidationException(['source' => [t('finance.error.contract_unavailable')]]);
                }
                $invoice = $this->billing->createLinked(CurrentUser::user(), 'invoice', $input, $sourceType, (int) ($input['source_id'] ?? 0));
            } else {
                $invoice = $this->invoices->create(CurrentUser::user(), $input);
            }
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
            // The issue form always posts final_rounding (0/1, defaulted to the organization setting);
            // a post without it falls back to the organization default.
            $rounding = $request->input('final_rounding');
            $this->invoices->issue(CurrentUser::user(), $id, $rounding === null || $rounding === '' ? null : $rounding === '1');
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
            $this->invoices->cancel(CurrentUser::user(), $id, (string) $request->input('reason', ''));
            Session::setFlash('success', t('invoices.flash.cancelled'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('invoices.flash.cancel_failed'));
        }

        return $this->redirect('/finance/invoices/' . $id);
    }

    /**
     * Stage 5.2 (L4): the invoice's financial audit events of the given (permitted) types, newest first.
     * @param list<string> $types
     * @return array<int, array{type: string, at: string, by: string, description: string}>
     */
    private function financialHistory(int $invoiceId, array $types): array
    {
        if ($types === []) {
            return [];
        }
        $rows = [];
        foreach ($this->timeline?->forSubject('invoice', $invoiceId) ?? [] as $event) {
            if (!in_array($event['type'] ?? '', $types, true)) {
                continue;
            }
            $actor = !empty($event['actor_user_id']) ? $this->users->findById((int) $event['actor_user_id']) : null;
            $rows[] = [
                'type' => (string) $event['type'],
                'at' => (string) $event['created_at'],
                'by' => $actor !== null ? trim($actor['first_name'] . ' ' . ($actor['last_name'] ?? '')) : '—',
                'description' => (string) $event['description'],
            ];
        }

        return $rows;
    }

    /** @return array<int, array{at: string, by: string, description: string}> */
    private function cancellations(int $invoiceId): array
    {
        $rows = [];
        foreach ($this->timeline?->forSubject('invoice', $invoiceId) ?? [] as $event) {
            if (($event['type'] ?? '') !== 'invoice_cancelled') {
                continue;
            }
            $actor = !empty($event['actor_user_id']) ? $this->users->findById((int) $event['actor_user_id']) : null;
            $rows[] = [
                'at' => (string) $event['created_at'],
                'by' => $actor !== null ? trim($actor['first_name'] . ' ' . ($actor['last_name'] ?? '')) : '—',
                'description' => (string) $event['description'],
            ];
        }

        return $rows;
    }

    /** Prefilled "New invoice" from a project (Stage 3-C); see showCreate(). */
    private function fromProject(int $projectId): Response
    {
        if ($this->projectInvoices === null) {
            throw new NotFoundException();
        }

        try {
            return $this->form(null, [], $this->projectInvoices->draftFromProject(CurrentUser::user(), $projectId));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.project.error.project_unavailable'));

            return $this->redirect($this->projectInvoices->sourceNotice(CurrentUser::user(), $projectId)['url'] ?? '/finance/invoices');
        }
    }

    /** Prefilled "New invoice" from an installment (Stage 2); see showCreate(). */
    private function fromInstallment(int $installmentId): Response
    {
        if ($this->billing === null) {
            throw new NotFoundException();
        }

        try {
            return $this->form(null, [], $this->billing->draftFromInstallment(CurrentUser::user(), $installmentId));
        } catch (ValidationException $e) {
            $back = $this->billing->installments()?->returnUrl(CurrentUser::user(), $installmentId);
            if ($back === null) {
                throw new NotFoundException();
            }
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.installment.error.unavailable'));

            return $this->redirect($back);
        }
    }

    private function form(?array $invoice, array $errors, array $old): Response
    {
        $organizationId = (int) CurrentUser::user()['organization_id'];
        $profile = $this->financialProfiles->find($organizationId);

        $contractSource = null;
        if ($invoice === null && ($old['source_type'] ?? '') === ContractBillingService::LINKED_TYPE && (int) ($old['source_id'] ?? 0) > 0) {
            $contractSource = $this->billing?->sourceNotice(CurrentUser::user(), (int) $old['source_id']);
        }
        if ($invoice === null && ($old['source_type'] ?? '') === InstallmentService::LINKED_TYPE && (int) ($old['source_id'] ?? 0) > 0) {
            $contractSource = $this->billing?->installmentSourceNotice(CurrentUser::user(), (int) $old['source_id']);
        }
        if ($invoice === null && ($old['source_type'] ?? '') === ProjectInvoiceService::SOURCE_TYPE && (int) ($old['source_id'] ?? 0) > 0) {
            $contractSource = $this->projectInvoices?->sourceNotice(CurrentUser::user(), (int) $old['source_id']);
        }

        return $this->viewWithLayout('finance/invoices/form', 'layout', [
            'contractSource' => $contractSource,
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

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->invoices->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/finance/invoices');
        if (!str_starts_with($back, '/finance/invoices') || str_contains($back, '//')) {
            $back = '/finance/invoices';
        }

        return $this->redirect($back);
    }
}
