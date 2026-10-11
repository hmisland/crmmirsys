<?php

declare(strict_types=1);

namespace Modules\Contracts\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Contracts\Services\ContractService;

use function App\Infrastructure\Theme\t;

/** Contract categories (contracts.manage_settings), /contracts/categories. */
final class ContractCategoryController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ContractService $contracts,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('contracts/categories', 'layout', [
            'categories' => $this->contracts->categories(CurrentUser::user()),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->attempt(
            fn () => $this->contracts->createCategory(CurrentUser::user(), (string) $request->input('name', '')),
            'contracts.flash.category_created'
        );
    }

    public function rename(Request $request): Response
    {
        return $this->attempt(
            fn () => $this->contracts->renameCategory(CurrentUser::user(), (int) $request->attribute('id'), (string) $request->input('name', '')),
            'contracts.flash.category_updated'
        );
    }

    public function delete(Request $request): Response
    {
        return $this->attempt(
            fn () => $this->contracts->deleteCategory(CurrentUser::user(), (int) $request->attribute('id')),
            'contracts.flash.category_deleted'
        );
    }

    private function attempt(callable $action, string $flashKey): Response
    {
        try {
            $action();
            Session::setFlash('success', t($flashKey));
        } catch (ValidationException $e) {
            Session::setFlash('error', t(array_values($e->errors)[0][0] ?? 'contracts.error.category_name_invalid'));
        }

        return $this->redirect('/contracts/categories');
    }
}
