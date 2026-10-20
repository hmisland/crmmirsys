<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;
use Modules\Finance\Services\ProductService;
use Modules\Finance\TaxProfileRepository;

final class ProductController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProductService $products,
        private readonly CurrencyRepository $currencies,
        private readonly TaxProfileRepository $taxProfiles,
        private readonly ?\Modules\Finance\Services\KalaCatalog $kala = null,
        private readonly ?\Modules\Finance\ProductTaxFavoriteRepository $taxFavorites = null,
    ) {
        parent::__construct($templates);
    }

    /** JSON for the product picker on proforma/invoice rows: ?q= code or name. */
    public function lookup(Request $request): Response
    {
        return Response::json([
            'data' => $this->products->lookup(CurrentUser::user(), (string) $request->query('q', '')),
        ]);
    }

    public function index(Request $request): Response
    {
        $taxSource = in_array($request->query('tax_source'), ['catalog', 'manual'], true) ? (string) $request->query('tax_source') : '';
        [$products, $pagination] = Pagination::slice($this->products->list(CurrentUser::user(), [
            'search' => trim((string) $request->query('q', '')),
            'tax_source' => $taxSource,
        ]), $request);

        return $this->viewWithLayout('finance/products/index', 'layout', [
            'products' => $products,
            'rowActions' => $this->products->rowPermissions(CurrentUser::user(), $products),
            'pagination' => $pagination,
            'q' => (string) $request->query('q', ''),
            'taxSource' => $taxSource,
            // "New product from the tax product ID": enabled only while kala.db can be read.
            'taxCatalogAvailable' => $this->kala?->available() ?? false,
        ]);
    }

    private static function defaultUnitName(): string
    {
        $code = (string) \App\Infrastructure\Config\Config::get('tax_units.default', '1627');
        foreach ((array) \App\Infrastructure\Config\Config::get('tax_units.units', []) as $unit) {
            if ($unit['code'] === $code) {
                return $unit['name'];
            }
        }

        return '';
    }

    public function showCreate(Request $request): Response
    {
        // ?tax_code=… (from the tax catalog's "+"): the form prefilled from the national list.
        $old = [];
        $item = $request->query('tax_code') !== null && $this->kala !== null ? $this->kala->find((string) $request->query('tax_code')) : null;
        if ($item !== null) {
            $exempt = \Modules\Finance\Services\KalaCatalog::exemptFlag($item['taxable']);
            $old = [
                'name' => $item['title'],
                'tax_product_code' => $item['id'],
                'tax_from_catalog' => '1',
                'vat_exempt' => $exempt !== null ? (string) $exempt : '',
                'vat_rate' => $item['vat'] !== null ? (string) $item['vat'] : '',
                // Same unit as an earlier product with this ID, otherwise the tax system's default ("عدد").
                'unit' => $this->products->unitForTaxCode($item['id']) ?? self::defaultUnitName(),
                'is_active' => '1',
            ];
        }

        return $this->viewWithLayout('finance/products/form', 'layout', [
            'product' => null,
            'currencies' => $this->currencies->all(),
            'taxProfiles' => $this->taxProfiles->availableFor((int) CurrentUser::user()['organization_id']),
            'errors' => [],
            'old' => $old,
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

        // Created from the national list: it now is one of "my products", no longer "frequently used".
        if (($product['tax_product_source'] ?? null) === 'catalog') {
            $this->taxFavorites?->remove((string) $product['tax_product_code']);
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

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->products->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/finance/products');
        if (!str_starts_with($back, '/finance/products') || str_contains($back, '//')) {
            $back = '/finance/products';
        }

        return $this->redirect($back);
    }
}
