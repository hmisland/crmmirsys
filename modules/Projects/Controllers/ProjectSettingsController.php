<?php

declare(strict_types=1);

namespace Modules\Projects\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Projects\Services\ProjectService;

use function App\Infrastructure\Theme\t;

/** Organization Projects settings (projects.manage_settings), /projects/settings. */
final class ProjectSettingsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProjectService $projects,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('projects/settings', 'layout', [
            'settings' => $this->projects->settings(CurrentUser::user()),
        ]);
    }

    public function save(Request $request): Response
    {
        $this->projects->saveSettings(CurrentUser::user(), $request->all());
        Session::setFlash('success', t('projects.flash.settings_saved'));

        return $this->redirect('/projects/settings');
    }
}
