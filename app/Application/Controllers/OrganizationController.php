<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\OrganizationService;
use App\Application\Services\SmsService;
use App\Core\Exceptions\NotFoundException;
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

use function App\Infrastructure\Theme\t;

final class OrganizationController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly OrganizationService $organizations,
        private readonly ?SmsService $sms = null,
    ) {
        parent::__construct($templates);
    }

    /** The SMS tile only appears when some provider serves the organization's language. */
    private function smsAvailable(): bool
    {
        return $this->sms !== null && $this->sms->providersFor((int) CurrentUser::user()['organization_id']) !== [];
    }

    public function show(Request $request): Response
    {
        $organization = $this->organizations->find((int) CurrentUser::user()['organization_id'], CurrentUser::user());

        return $this->viewWithLayout('org/settings', 'layout', [
            'organization' => $organization,
            'errors' => [],
            'old' => [],
            'canEdit' => $this->organizations->canEdit(CurrentUser::user()),
            'smsAvailable' => $this->smsAvailable(),
            'enabledModules' => $this->enabledModules(),
        ]);
    }

    public function update(Request $request): Response
    {
        try {
            $this->organizations->update(
                (int) CurrentUser::user()['organization_id'],
                CurrentUser::user(),
                $request->all(),
                $request->file('logo')
            );
        } catch (ValidationException $e) {
            $organization = $this->organizations->find((int) CurrentUser::user()['organization_id'], CurrentUser::user());

            return $this->viewWithLayout('org/settings', 'layout', [
                'organization' => $organization,
                'errors' => $e->errors,
                'old' => $request->all(),
                'canEdit' => true,
                'smsAvailable' => $this->smsAvailable(),
                'enabledModules' => $this->enabledModules(),
            ], 422);
        }

        Session::setFlash('success', t('org.settings_saved'));

        return $this->redirect('/org/settings');
    }

    /**
     * The organization's logo, for its own members only (settings page,
     * invoice print view). Served from storage/, never a public URL.
     */
    public function logo(Request $request): Response
    {
        $user = CurrentUser::user();
        $organization = $this->organizations->find((int) ($user['organization_id'] ?? 0), $user);
        $path = $this->organizations->logoPath($organization);

        if ($path === null) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=300');
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

        $keys = ['sales', 'wallet', 'support', 'internal_mail', 'notifications', 'finance', 'knowledge', 'customer_portal'];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $resolver->resolvedForUser($key, $user);
        }

        return $result;
    }
}
