<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\ProductRepository;
use Modules\Finance\ProductTaxFavoriteRepository;
use Modules\Finance\Services\KalaCatalog;

/**
 * Products → "new product from the tax product ID" (products.create):
 * the organization's products that carry a tax ID ("my products"), its
 * "frequently used" list entries, and a live search in the national list
 * (kala.db). Adding a product opens the normal product form prefilled
 * (/finance/products/create?tax_code=…); the user completes and saves it.
 * Without kala.db every page here is 404 and the products page shows the
 * button disabled.
 */
final class TaxCatalogController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly KalaCatalog $kala,
        private readonly ProductTaxFavoriteRepository $favorites,
        private readonly ProductRepository $products,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireCatalog();
        $mine = array_values(array_filter($this->products->search([]), fn (array $p): bool => !empty($p['tax_product_code'])));

        return $this->viewWithLayout('finance/products/tax-catalog', 'layout', [
            'myProducts' => $mine,
            'favorites' => $this->favorites->all(),
        ]);
    }

    /** JSON: matches in the national list, minus those already in "frequently used". */
    public function search(Request $request): Response
    {
        $this->requireCatalog();
        $favoriteCodes = array_column($this->favorites->all(), 'tax_product_code');
        $items = array_values(array_filter(
            $this->kala->search((string) $request->query('q', ''), 30),
            fn (array $i): bool => !in_array($i['id'], $favoriteCodes, true)
        ));

        return Response::json(['items' => $items]);
    }

    /** JSON: star → "frequently used" (only IDs that really are in the list). */
    public function addFavorite(Request $request): Response
    {
        $this->requireCatalog();
        $item = $this->kala->find((string) $request->input('code', ''));
        if ($item === null) {
            return Response::json(['ok' => false], 404);
        }
        $this->favorites->add($item, (int) CurrentUser::user()['id']);

        return Response::json(['ok' => true, 'item' => $item]);
    }

    /** JSON: green star → back out of "frequently used". */
    public function removeFavorite(Request $request): Response
    {
        $this->requireCatalog();
        $code = KalaCatalog::code((string) $request->input('code', ''));
        if ($code !== null) {
            $this->favorites->remove($code);
        }

        return Response::json(['ok' => $code !== null, 'item' => $code !== null ? $this->kala->find($code) : null]);
    }

    private function requireCatalog(): void
    {
        if (!$this->kala->available()) {
            throw new NotFoundException();
        }
    }
}
