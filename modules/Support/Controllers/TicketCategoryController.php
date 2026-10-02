<?php

declare(strict_types=1);

namespace Modules\Support\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Support\Services\TicketCategoryService;

final class TicketCategoryController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly TicketCategoryService $categories)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('support/categories', 'layout', [
            'categories' => $this->categories->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $this->categories->create(CurrentUser::user(), (string) $request->input('name', ''));
            Session::setFlash('success', 'Category added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add category.');
        }

        return $this->redirect('/support/categories');
    }

    public function delete(Request $request): Response
    {
        $this->categories->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Category deleted.');

        return $this->redirect('/support/categories');
    }
}
