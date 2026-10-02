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
use Modules\Finance\Services\FinancialPeriodService;

final class FinancialPeriodController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly FinancialPeriodService $periods,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('finance/periods/index', 'layout', [
            'periods' => $this->periods->list(CurrentUser::user()),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $this->periods->create(CurrentUser::user(), $request->all());
            Session::setFlash('success', 'Financial period created.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not create this period.');
        }

        return $this->redirect('/finance/periods');
    }

    public function close(Request $request): Response
    {
        try {
            $this->periods->close(CurrentUser::user(), (int) $request->attribute('id'));
            Session::setFlash('success', 'Period closed.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not close this period.');
        }

        return $this->redirect('/finance/periods');
    }

    public function reopen(Request $request): Response
    {
        try {
            $this->periods->reopen(CurrentUser::user(), (int) $request->attribute('id'));
            Session::setFlash('success', 'Period reopened.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not reopen this period.');
        }

        return $this->redirect('/finance/periods');
    }
}
