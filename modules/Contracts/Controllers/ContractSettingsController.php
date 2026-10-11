<?php

declare(strict_types=1);

namespace Modules\Contracts\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Contracts\Services\ContractService;

use function App\Infrastructure\Theme\t;

/** Organization Contracts settings (contracts.manage_settings), /contracts/settings. */
final class ContractSettingsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ContractService $contracts,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('contracts/settings', 'layout', [
            'settings' => $this->contracts->settings(CurrentUser::user()),
        ]);
    }

    public function save(Request $request): Response
    {
        $this->contracts->saveSettings(CurrentUser::user(), $request->all());
        Session::setFlash('success', t('contracts.flash.settings_saved'));

        return $this->redirect('/contracts/settings');
    }
}
