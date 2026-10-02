<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\ProductService;
use Modules\Finance\TaxProfileRepository;

final class ProductController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProductService $products,
        private readonly CurrencyRepository $currencies,
        private readonly TaxProfileRepository $taxProfiles,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $products = $this->products->list(CurrentUser::user(), [
            'search' => (string) $request->query('q', ''),
        ]);

        return $this->viewWithLayout('finance/products/index', 'layout', [
            'products' => $products,
            'q' => (string) $request->query('q', ''),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->viewWithLayout('finance/products/form', 'layout', [
            'product' => null,
            'currencies' => $this->currencies->all(),
            'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $product = $this->products->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('finance/products/form', 'layout', [
                'product' => null,
                'currencies' => $this->currencies->all(),
                'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Product added.');

        return $this->redirect('/finance/products/' . $product['id'] . '/edit');
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->viewWithLayout('finance/products/form', 'layout', [
            'product' => $this->products->find(CurrentUser::user(), $id),
            'currencies' => $this->currencies->all(),
            'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->products->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('finance/products/form', 'layout', [
                'product' => $this->products->find(CurrentUser::user(), $id),
                'currencies' => $this->currencies->all(),
                'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Product updated.');

        return $this->redirect('/finance/products/' . $id . '/edit');
    }

    public function delete(Request $request): Response
    {
        $this->products->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Product deleted.');

        return $this->redirect('/finance/products');
    }
}
