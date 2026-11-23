<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Services\Customer\CustomerCatalogService;
use Illuminate\Http\Request;

/**
 * WAFFRLI customer API (2026-11-03) — product favorites. Order and driver ratings are handled by CustomerRatingController.
 */
class CustomerEngagementController extends CustomerController
{
    public function __construct(protected CustomerCatalogService $catalog) {}

    protected function activeProduct(string $id): Product
    {
        $product = Product::query()->whereKey($id)->where('is_active', true)->whereHas('store', fn ($q) => $q->where('is_active', true))->first();
        if (! $product) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }

        return $product;
    }

    public function favorites(Request $request)
    {
        return $this->respond(function () use ($request) {
            $user = $this->customer($request);
            $products = $user->favoriteProducts()->where('is_active', true)->with(['store', 'categories'])->orderByPivot('created_at', 'desc')->get();

            return ['favorites' => $products->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'image_url' => $this->catalog->url($p->image),
                'store' => ['id' => $p->store->id, 'name' => $p->store->name],
            ])->values()];
        });
    }

    public function addFavorite(Request $request, string $product)
    {
        return $this->respond(function () use ($request, $product) {
            $this->customer($request)->favoriteProducts()->syncWithoutDetaching([$this->activeProduct($product)->id => ['created_at' => now()]]);

            return ['message' => __('messages.customer.favorite_added'), 'product_id' => (int) $product, 'is_favorite' => true];
        });
    }

    public function removeFavorite(Request $request, string $product)
    {
        return $this->respond(function () use ($request, $product) {
            $this->customer($request)->favoriteProducts()->detach((int) $product);

            return ['message' => __('messages.customer.favorite_removed'), 'product_id' => (int) $product, 'is_favorite' => false];
        });
    }
}
