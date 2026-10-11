<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\ProductRepository;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\ProformaService;
use Modules\Finance\TaxProfileRepository;

use function App\Infrastructure\Theme\t;

final class ProformaController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProformaService $proformas,
        private readonly ContactRepository $contacts,
        private readonly CurrencyRepository $currencies,
        private readonly TaxProfileRepository $taxProfiles,
        private readonly ProductRepository $products,
        private readonly FinancialProfileRepository $financialProfiles,
        private readonly ?ContractBillingService $billing = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key, '')) ? (string) $request->query($key) : '';
        $filters = [
            'status' => in_array($request->query('status'), ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'], true) ? (string) $request->query('status') : '',
            'search' => trim((string) $request->query('q', '')),
            'date_from' => $date('from'),
            'date_to' => $date('to'),
        ];

        // Database-side paging; re-read once if the requested page was past the end.
        $requested = Pagination::fromRequest($request, PHP_INT_MAX);
        $page = $this->proformas->listPage(CurrentUser::user(), $filters, $requested->perPage, $requested->offset());
        $pagination = Pagination::fromRequest($request, $page['total']);
        if ($pagination->offset() !== $requested->offset()) {
            $page = $this->proformas->listPage(CurrentUser::user(), $filters, $pagination->perPage, $pagination->offset());
        }

        return $this->viewWithLayout('finance/proformas/index', 'layout', [
            'proformas' => $page['rows'],
            'rowActions' => $this->proformas->rowPermissions(CurrentUser::user(), $page['rows']),
            'pagination' => $pagination,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $proforma = $this->proformas->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('finance/proformas/show', 'layout', [
            'contractLink' => $this->billing?->linkOf(CurrentUser::user(), 'proforma', $id),
            'proforma' => $proforma,
            'items' => $this->proformas->itemsFor($id),
            'contact' => $proforma['contact_id'] !== null ? $this->contacts->findById((int) $proforma['contact_id']) : null,
        ]);
    }

    public function showCreate(Request $request): Response
    {
        // "New proforma" on a contract page (docs/contract-finance-design.md):
        // prefilled from the contract, read through RecordTypeRegistry only.
        // A contract this user can't view → 404, never a prefilled form.
        // "New proforma" from a contract installment (Stage 2): an installment or
        // contract this user can't see → 404; one that can't be billed now (void,
        // already has an active document, contract not active / expired) → back
        // to the contract with the reason.
        if ($request->query('from_installment') !== null) {
            return $this->fromInstallment((int) $request->query('from_installment', 0));
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
            if ($sourceType !== '' || !empty($input['source_id'])) {
                if ($this->billing === null) {
                    throw new ValidationException(['source' => [t('finance.error.contract_unavailable')]]);
                }
                $proforma = $this->billing->createLinked(CurrentUser::user(), 'proforma', $input, $sourceType, (int) ($input['source_id'] ?? 0));
            } else {
                $proforma = $this->proformas->create(CurrentUser::user(), $input);
            }
        } catch (ValidationException $e) {
            return $this->form(null, $e->errors, $request->all());
        }

        Session::setFlash('success', 'Proforma created.');

        return $this->redirect('/finance/proformas/' . $proforma['id']);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $proforma = $this->proformas->find(CurrentUser::user(), $id);

        return $this->form($proforma, [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->proformas->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->form($this->proformas->find(CurrentUser::user(), $id), $e->errors, $request->all());
        }

        Session::setFlash('success', 'Proforma updated.');

        return $this->redirect('/finance/proformas/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->proformas->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Proforma deleted.');

        return $this->redirect('/finance/proformas');
    }

    /** Prefilled "New proforma" from an installment (Stage 2); see showCreate(). */
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

    private function form(?array $proforma, array $errors, array $old): Response
    {
        $organizationId = (int) CurrentUser::user()['organization_id'];
        $profile = $this->financialProfiles->find($organizationId);

        $contractSource = null;
        if ($proforma === null && ($old['source_type'] ?? '') === ContractBillingService::LINKED_TYPE && (int) ($old['source_id'] ?? 0) > 0) {
            $contractSource = $this->billing?->sourceNotice(CurrentUser::user(), (int) $old['source_id']);
        }
        if ($proforma === null && ($old['source_type'] ?? '') === InstallmentService::LINKED_TYPE && (int) ($old['source_id'] ?? 0) > 0) {
            $contractSource = $this->billing?->installmentSourceNotice(CurrentUser::user(), (int) $old['source_id']);
        }

        return $this->viewWithLayout('finance/proformas/form', 'layout', [
            'contractSource' => $contractSource,
            'proforma' => $proforma,
            'items' => $proforma !== null ? $this->proformas->itemsFor((int) $proforma['id']) : [],
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
        $result = $this->proformas->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/finance/proformas');
        if (!str_starts_with($back, '/finance/proformas') || str_contains($back, '//')) {
            $back = '/finance/proformas';
        }

        return $this->redirect($back);
    }
}
