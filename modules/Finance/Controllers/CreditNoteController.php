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
use Modules\Finance\Services\CreditNoteService;

final class CreditNoteController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly CreditNoteService $creditNotes,
    ) {
        parent::__construct($templates);
    }

    public function create(Request $request): Response
    {
        $invoiceId = (int) $request->attribute('id');

        try {
            $result = $this->creditNotes->issue(CurrentUser::user(), $invoiceId, $request->all());
            Session::setFlash('success', 'Credit note ' . $result['credit_note_number'] . ' issued.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not issue this credit note.');
        }

        return $this->redirect('/finance/invoices/' . $invoiceId);
    }
}
