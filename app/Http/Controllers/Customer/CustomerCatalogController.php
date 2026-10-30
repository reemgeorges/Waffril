<?php

namespace App\Http\Controllers\Customer;

use App\Services\Customer\CustomerCatalogService;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — browsing limited to the delivery city. */
class CustomerCatalogController extends CustomerController
{
    public function __construct(protected CustomerCatalogService $catalog) {}

    public function storeCategories()
    {
        return $this->respond(fn () => ['store_categories' => $this->catalog->storeCategories()]);
    }

    public function productCategories()
    {
        return $this->respond(fn () => ['categories' => $this->catalog->productCategories()]);
    }

    public function branches(Request $request)
    {
        return $this->respond(function () use ($request) {
            $page = $this->catalog->branches($this->customer($request), $request->only(['store_category_id', 'search']), $this->perPage($request));

            return $this->paginated($page, 'branches', fn ($row) => $row);
        });
    }

    public function branch(Request $request, string $branch)
    {
        return $this->respond(fn () => ['branch' => $this->catalog->branchDetail($this->customer($request), $branch)]);
    }

    public function branchProducts(Request $request, string $branch)
    {
        return $this->respond(function () use ($request, $branch) {
            $page = $this->catalog->branchProducts($this->customer($request), $branch, [
                'category_id' => $request->query('category_id'), 'search' => $request->query('search'),
                'include_unavailable' => $request->boolean('include_unavailable'),
            ], $this->perPage($request, 30));

            return $this->paginated($page, 'products', fn ($row) => $row);
        });
    }

    public function product(Request $request, string $branch, string $product)
    {
        return $this->respond(fn () => ['product' => $this->catalog->productDetail($this->customer($request), $branch, $product)]);
    }

    public function search(Request $request)
    {
        return $this->respond(function () use ($request) {
            $term = trim((string) $request->query('q'));
            if (mb_strlen($term) < 2) {
                return ['branches' => [], 'products' => []];
            }

            return $this->catalog->search($this->customer($request), mb_substr($term, 0, 60));
        });
    }

    public function offers(Request $request)
    {
        return $this->respond(fn () => ['offers' => $this->catalog->offers($this->customer($request))]);
    }
}
