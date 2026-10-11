<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Deliberately minimal for Phase 3 — a real platform-level admin
 * panel (module enable/disable, plans, entitlements) is Phase 4's
 * Module Manager territory per Phase 0 §74. This exists now so a
 * Super Admin isn't looking at a completely empty account: a
 * read-mostly view of every organization on the platform, using
 * OrganizationRepository/UserRepository directly (neither is
 * tenant-scoped, so no SuperAdminContext wrapper is needed here —
 * there is no organization_id column on `organizations` to bypass).
 */
final class SuperAdminController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly OrganizationRepository $organizations,
        private readonly UserRepository $users,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        $q = trim((string) $request->query('q', ''));
        $organizations = array_filter($this->organizations->all(), fn ($o) => $q === '' || mb_stripos($o['name'] . ' ' . $o['slug'], $q) !== false);
        [$organizations, $pagination] = Pagination::slice($organizations, $request);

        $userCounts = [];
        foreach ($organizations as $organization) {
            $userCounts[$organization['id']] = $this->users->countInOrganization((int) $organization['id']);
        }

        return $this->viewWithLayout('admin/organizations', 'layout', [
            'organizations' => $organizations,
            'userCounts' => $userCounts,
            'pagination' => $pagination,
            'q' => $q,
        ]);
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
