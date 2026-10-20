<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ModuleActivationRequestService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Organization settings → Modules → Modules & Features (/org/modules/catalog):
 * what the CRM offers, grouped by purpose, with the organization's real
 * activation status and "Request activation". organization.edit (route +
 * service). Activation itself stays the Super Admin's existing decision.
 */
final class ModuleCatalogController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly ModuleActivationRequestService $requests)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('org/module-catalog', 'layout', [
            'categories' => $this->requests->catalog((array) CurrentUser::user()),
        ]);
    }

    public function request(Request $request): Response
    {
        $key = (string) $request->attribute('key');
        try {
            $this->requests->request((array) CurrentUser::user(), $key);
            Session::setFlash('success', t('catalog.requested'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'catalog.error.unknown_module')));
        }

        return $this->redirect('/org/modules/catalog#module-' . (preg_match('/^[a-z0-9_]{1,60}$/', $key) === 1 ? $key : ''));
    }
}
