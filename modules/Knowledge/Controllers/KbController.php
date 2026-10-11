<?php

declare(strict_types=1);

namespace Modules\Knowledge\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Knowledge\Services\KbService;

final class KbController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly KbService $kb)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'category_id' => (string) $request->query('category_id', ''),
        ];

        [$articles, $pagination] = Pagination::slice($this->kb->list(CurrentUser::user(), $filters), $request);

        return $this->viewWithLayout('kb/index', 'layout', [
            'articles' => $articles,
            'pagination' => $pagination,
            'categories' => $this->kb->categories(),
            'q' => $filters['search'],
            'categoryId' => $filters['category_id'],
        ]);
    }

    public function show(Request $request): Response
    {
        return $this->viewWithLayout('kb/show', 'layout', [
            'article' => $this->kb->find(CurrentUser::user(), (int) $request->attribute('id')),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->viewWithLayout('kb/form', 'layout', [
            'article' => null,
            'categories' => $this->kb->categories(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $article = $this->kb->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('kb/form', 'layout', [
                'article' => null,
                'categories' => $this->kb->categories(),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        return $this->redirect('/kb/articles/' . $article['id']);
    }

    public function showEdit(Request $request): Response
    {
        $article = $this->kb->find(CurrentUser::user(), (int) $request->attribute('id'));

        return $this->viewWithLayout('kb/form', 'layout', [
            'article' => $article,
            'categories' => $this->kb->categories(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->kb->update(CurrentUser::user(), $id, $request->all());
            Session::setFlash('success', 'Article updated.');
        } catch (ValidationException $e) {
            return $this->viewWithLayout('kb/form', 'layout', [
                'article' => $this->kb->find(CurrentUser::user(), $id),
                'categories' => $this->kb->categories(),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        return $this->redirect('/kb/articles/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->kb->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Article deleted.');

        return $this->redirect('/kb/articles');
    }

    public function createCategory(Request $request): Response
    {
        try {
            $this->kb->createCategory(CurrentUser::user(), (string) $request->input('name', ''));
            Session::setFlash('success', 'Category added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add category.');
        }

        return $this->redirect('/kb/categories');
    }

    public function categoriesPage(Request $request): Response
    {
        return $this->viewWithLayout('kb/categories', 'layout', [
            'categories' => $this->kb->categories(),
        ]);
    }

    public function deleteCategory(Request $request): Response
    {
        $this->kb->deleteCategory(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Category deleted.');

        return $this->redirect('/kb/categories');
    }
}
