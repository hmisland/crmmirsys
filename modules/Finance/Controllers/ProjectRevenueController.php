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
use Modules\Finance\Services\ProjectRevenueService;

use function App\Infrastructure\Theme\t;

/**
 * Attach / detach an invoice to a project (Stage 3-B), from the project's
 * revenue panel. Thin: every rule lives in ProjectRevenueService. Routes add
 * Auth + Finance module + RbacMiddleware('invoices.edit') + CSRF.
 */
final class ProjectRevenueController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProjectRevenueService $revenue,
    ) {
        parent::__construct($templates);
    }

    public function attach(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');

        try {
            $this->revenue->attach(CurrentUser::user(), $projectId, (int) $request->input('invoice_id', 0));
            Session::setFlash('success', t('finance.project.flash.attached'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.project.error.unavailable'));
        }

        return $this->redirect($this->revenue->projectUrl($projectId) ?? '/finance/invoices');
    }

    public function detach(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');

        try {
            $this->revenue->detach(CurrentUser::user(), $projectId, (int) $request->attribute('invoiceId'));
            Session::setFlash('success', t('finance.project.flash.detached'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.project.error.unavailable'));
        }

        return $this->redirect($this->revenue->projectUrl($projectId) ?? '/finance/invoices');
    }
}
