<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\OrganizationService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class OrganizationController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly OrganizationService $organizations)
    {
        parent::__construct($templates);
    }

    public function show(Request $request): Response
    {
        $organization = $this->organizations->find((int) CurrentUser::user()['organization_id'], CurrentUser::user());

        return $this->viewWithLayout('org/settings', 'layout', [
            'organization' => $organization,
            'errors' => [],
            'enabledModules' => $this->enabledModules(),
        ]);
    }

    public function update(Request $request): Response
    {
        try {
            $this->organizations->update(
                (int) CurrentUser::user()['organization_id'],
                CurrentUser::user(),
                $request->all()
            );
        } catch (ValidationException $e) {
            $organization = $this->organizations->find((int) CurrentUser::user()['organization_id'], CurrentUser::user());

            return $this->viewWithLayout('org/settings', 'layout', [
                'organization' => $organization,
                'errors' => $e->errors,
                'enabledModules' => $this->enabledModules(),
            ], 422);
        }

        Session::setFlash('success', 'Organization settings updated.');

        return $this->redirect('/org/settings');
    }

    /**
     * Every module the settings hub might link out to, keyed by
     * module key — the hub template shows a section only when its
     * module is actually enabled for this org, same check the main
     * nav already uses.
     *
     * @return array<string, bool>
     */
    private function enabledModules(): array
    {
        $user = CurrentUser::user();
        $resolver = new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository());

        $keys = ['sales', 'wallet', 'support', 'internal_mail', 'notifications', 'finance', 'knowledge'];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $resolver->resolvedForUser($key, $user);
        }

        return $result;
    }
}
