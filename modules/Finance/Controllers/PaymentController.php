<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\PaymentService;

final class PaymentController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly PaymentService $payments,
    ) {
        parent::__construct($templates);
    }

    public function create(Request $request): Response
    {
        $invoiceId = (int) $request->attribute('id');

        try {
            $this->payments->record(CurrentUser::user(), $invoiceId, $request->all());
            Session::setFlash('success', 'Payment recorded.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not record this payment.');
        }

        return $this->redirect('/finance/invoices/' . $invoiceId);
    }
}
