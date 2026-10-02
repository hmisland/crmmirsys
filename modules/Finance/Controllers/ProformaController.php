<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\ProductRepository;
use Modules\Finance\Services\ProformaService;
use Modules\Finance\TaxProfileRepository;

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
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('finance/proformas/index', 'layout', [
            'proformas' => $this->proformas->list(CurrentUser::user()),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $proforma = $this->proformas->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('finance/proformas/show', 'layout', [
            'proforma' => $proforma,
            'items' => $this->proformas->itemsFor($id),
            'contact' => $proforma['contact_id'] !== null ? $this->contacts->findById((int) $proforma['contact_id']) : null,
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->form(null, [], []);
    }

    public function create(Request $request): Response
    {
        try {
            $proforma = $this->proformas->create(CurrentUser::user(), $request->all());
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

    private function form(?array $proforma, array $errors, array $old): Response
    {
        $organizationId = (int) CurrentUser::user()['organization_id'];
        $profile = $this->financialProfiles->find($organizationId);

        return $this->viewWithLayout('finance/proformas/form', 'layout', [
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
}
