<?php

declare(strict_types=1);

namespace Modules\Notification\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Notification\Services\NotificationSettingsService;

final class NotificationPlatformController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly NotificationSettingsService $settings)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->viewWithLayout('notification/platform-settings', 'layout', [
            'settings' => $this->settings->get(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireSuperAdmin();

        try {
            $this->settings->update(CurrentUser::user(), (string) $request->input('bale_cost', '0'), (string) $request->input('sms_cost', '0'));
            Session::setFlash('success', 'Notification pricing updated.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update.');
        }

        return $this->redirect('/admin/notifications/settings');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();
        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
