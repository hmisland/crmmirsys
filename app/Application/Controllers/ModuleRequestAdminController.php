<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ModuleActivationRequestService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Super Admin → Module requests (/admin/module-requests): pending first;
 * approve (the existing per-organization entitlement) or reject with an
 * optional reason. The same actions are offered on the organization's
 * module screen (/admin/organizations/{id}/modules).
 */
final class ModuleRequestAdminController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly ModuleActivationRequestService $requests)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = $this->superAdmin();
        $filters = ['status' => (string) $request->query('status', '')];
        $pagination = Pagination::fromRequest($request, $this->requests->adminCount($user, $filters));

        return $this->viewWithLayout('admin/module-requests', 'layout', [
            'rows' => $this->requests->adminPage($user, $filters, $pagination->perPage, $pagination->offset()),
            'filters' => $filters,
            'pagination' => $pagination,
        ]);
    }

    public function approve(Request $request): Response
    {
        $user = $this->superAdmin();
        try {
            $wasActive = $this->requests->approve($user, (int) $request->attribute('id'));
            Session::setFlash('success', t($wasActive ? 'catalog.admin.approved_already_active' : 'catalog.admin.approved'));
        } catch (ValidationException $e) {
            // Entitlement errors from ModuleService are plain messages; ours are translation keys.
            Session::setFlash('error', t('catalog.admin.error.not_approved', ['reason' => t((string) (array_values($e->errors)[0][0] ?? ''))]));
        }

        return $this->back($request);
    }

    public function reject(Request $request): Response
    {
        $user = $this->superAdmin();
        try {
            $this->requests->reject($user, (int) $request->attribute('id'), (string) $request->input('reason', ''));
            Session::setFlash('success', t('catalog.admin.rejected'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'catalog.admin.error.already_processed')));
        }

        return $this->back($request);
    }

    /** Back to the list, or to the organization's module screen when the action came from there. */
    private function back(Request $request): Response
    {
        $organizationId = (int) $request->input('back_organization', 0);

        return $this->redirect($organizationId > 0 ? '/admin/organizations/' . $organizationId . '/modules' : '/admin/module-requests');
    }

    private function superAdmin(): array
    {
        $user = CurrentUser::user();
        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }

        return $user;
    }
}
