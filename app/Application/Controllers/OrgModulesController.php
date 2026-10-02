<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ModuleService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Org Admin's view of modules. Per the entitlement/restriction split
 * (see ModuleService's class docblock): this page is READ-ONLY for
 * whether a module is entitled to the organization at all (that's
 * Super Admin's call, via OrganizationModuleAdminController) — what
 * an Org Admin actually controls here is restricting specific members
 * of their own org from an already-entitled module.
 */
final class OrgModulesController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly ModuleService $modules)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $organizationId = (int) CurrentUser::user()['organization_id'];

        return $this->viewWithLayout('org/modules', 'layout', [
            'modules' => $this->modules->listForOrganization($organizationId),
        ]);
    }

    public function manageAccess(Request $request): Response
    {
        $moduleId = (int) $request->attribute('id');

        try {
            $data = $this->modules->userAccessForModule(CurrentUser::user(), $moduleId);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not load module access.');

            return $this->redirect('/org/modules');
        }

        return $this->viewWithLayout('org/module-access', 'layout', [
            'module' => $this->modules->findModule($moduleId),
            'users' => $data['users'],
            'overrides' => $data['overrides'],
        ]);
    }

    public function restrictUser(Request $request): Response
    {
        return $this->setUserAccess($request, true);
    }

    public function allowUser(Request $request): Response
    {
        return $this->setUserAccess($request, false);
    }

    private function setUserAccess(Request $request, bool $restricted): Response
    {
        $moduleId = (int) $request->attribute('id');

        try {
            $this->modules->setUserAccess(CurrentUser::user(), (int) $request->attribute('userId'), $moduleId, $restricted);
            Session::setFlash('success', $restricted ? 'User restricted from this module.' : 'Restriction removed.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update access.');
        }

        return $this->redirect('/org/modules/' . $moduleId . '/access');
    }
}
