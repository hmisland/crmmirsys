<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\InstallmentService;

use function App\Infrastructure\Theme\t;

/**
 * Contract installment schedule (Stage 2). Thin: every rule — contract
 * access through RecordTypeRegistry, contracts.edit on the contract, the
 * contract-status rules, row locks — lives in InstallmentService. Routes
 * add Auth + Finance module + RbacMiddleware('contracts.edit') + CSRF.
 */
final class InstallmentController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly InstallmentService $installments,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
    ) {
        parent::__construct($templates);
    }

    /** "Add installment" on the contract page's Billing panel. */
    public function create(Request $request): Response
    {
        $contractId = (int) $request->attribute('id');

        try {
            $this->installments->create(CurrentUser::user(), $contractId, $request->all());
            Session::setFlash('success', t('finance.installment.flash.created'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.installment.flash.failed'));
        }

        return $this->redirect($this->installments->contractUrl($contractId) ?? '/finance/invoices');
    }

    public function showEdit(Request $request): Response
    {
        return $this->page((int) $request->attribute('id'), [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $installment = $this->installments->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->page($id, $e->errors, $request->all());
        }
        Session::setFlash('success', t('finance.installment.flash.updated'));

        return $this->redirect($this->installments->contractUrl((int) $installment['linked_id']) ?? '/finance/invoices');
    }

    public function void(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $installment = $this->installments->void(CurrentUser::user(), $id, (string) $request->input('reason', ''));
        } catch (ValidationException $e) {
            return $this->page($id, $e->errors, $request->all());
        }
        Session::setFlash('success', t('finance.installment.flash.voided'));

        return $this->redirect($this->installments->contractUrl((int) $installment['linked_id']) ?? '/finance/invoices');
    }

    /** The edit / void page; the same access as the actions themselves (404 / 403 otherwise). */
    private function page(int $id, array $errors, array $old): Response
    {
        $data = $this->installments->forEdit(CurrentUser::user(), $id);

        return $this->viewWithLayout('finance/installments/edit', 'layout', $data + [
            'currencyCode' => $this->currencies->findById((int) $data['installment']['currency_id'])['code'] ?? '',
            'errors' => $errors,
            'old' => $old,
        ], $errors === [] ? 200 : 422);
    }
}
