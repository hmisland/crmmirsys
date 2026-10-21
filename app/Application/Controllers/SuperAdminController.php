<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Domain\Users\Events\UserEmailVerified;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

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
        private readonly EventBus $events,
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

    /**
     * Users whose email is not verified yet — for when the verification
     * email never arrived, so a Super Admin can verify them by hand.
     */
    public function unverifiedUsers(Request $request): Response
    {
        $this->requireSuperAdmin();

        $q = trim((string) $request->query('q', ''));
        $users = array_filter($this->users->findUnverified(), fn ($u) => $q === '' || mb_stripos($u['email'] . ' ' . $u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['organization_name'], $q) !== false);
        [$users, $pagination] = Pagination::slice($users, $request);

        return $this->viewWithLayout('admin/unverified-users', 'layout', [
            'users' => $users,
            'pagination' => $pagination,
            'q' => $q,
        ]);
    }

    /** Marks one user's email as verified (same effect as opening the verification link). */
    public function verifyUser(Request $request): Response
    {
        $this->requireSuperAdmin();

        $user = $this->users->findById((int) $request->attribute('id'));
        if ($user === null) {
            throw new NotFoundException();
        }

        if ($user['email_verified_at'] === null) {
            $this->users->markEmailVerified((int) $user['id']);
            $this->events->dispatch(new UserEmailVerified((int) $user['id']));
        }
        Session::setFlash('success', t('admin.unverified.verified', ['email' => (string) $user['email']]));

        return $this->redirect('/admin/unverified-users');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
