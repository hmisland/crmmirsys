<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ModuleService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Platform-wide module management — Super Admin only, checked
 * directly (is_super_admin) rather than through AuthorizationEngine,
 * same reasoning as SuperAdminController: Super Admin is an identity
 * fact outside the per-organization RBAC system entirely (Phase 0 §15).
 */
final class ModuleAdminController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly ModuleService $modules)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->viewWithLayout('admin/modules', 'layout', [
            'modules' => $this->modules->listAll(),
            'error' => null,
        ]);
    }

    public function sync(Request $request): Response
    {
        $this->requireSuperAdmin();

        $this->modules->syncFromRegistry();
        Session::setFlash('success', 'Modules synced from disk.');

        return $this->redirect('/admin/modules');
    }

    public function enable(Request $request): Response
    {
        return $this->setStatus($request, true);
    }

    public function disable(Request $request): Response
    {
        return $this->setStatus($request, false);
    }

    private function setStatus(Request $request, bool $active): Response
    {
        $this->requireSuperAdmin();

        try {
            $this->modules->setGlobalStatus(CurrentUser::user(), (int) $request->attribute('id'), $active);
            Session::setFlash('success', $active ? 'Module enabled.' : 'Module disabled.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update module.');
        }

        return $this->redirect('/admin/modules');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
