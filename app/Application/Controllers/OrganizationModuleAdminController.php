<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ModuleService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Per-organization module entitlement — Super Admin only. This is the
 * commercial "grant/revoke what this customer paid for" control that
 * used to live (incorrectly) on the Org Admin's own /org/modules page.
 */
final class OrganizationModuleAdminController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ModuleService $modules,
        private readonly OrganizationRepository $organizations,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        $organizationId = (int) $request->attribute('id');
        $organization = $this->organizations->findById($organizationId);

        if ($organization === null) {
            throw new NotFoundException('Organization not found.');
        }

        return $this->viewWithLayout('admin/organization-modules', 'layout', [
            'organization' => $organization,
            'modules' => $this->modules->listForOrganization($organizationId),
        ]);
    }

    public function enable(Request $request): Response
    {
        return $this->setEntitlement($request, true);
    }

    public function disable(Request $request): Response
    {
        return $this->setEntitlement($request, false);
    }

    private function setEntitlement(Request $request, bool $enabled): Response
    {
        $this->requireSuperAdmin();

        $organizationId = (int) $request->attribute('id');

        try {
            $this->modules->setOrganizationEntitlement(CurrentUser::user(), $organizationId, (int) $request->attribute('moduleId'), $enabled);
            Session::setFlash('success', $enabled ? 'Module enabled for this organization.' : 'Module disabled for this organization.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update entitlement.');
        }

        return $this->redirect('/admin/organizations/' . $organizationId . '/modules');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
