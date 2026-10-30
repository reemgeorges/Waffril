<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\City;
use App\Models\Currency;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Catalog\BranchHoursService;
use App\Support\Database\JsonSearch;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * WAFFRLI customer API (2026-11-03) — read-only catalog for the customer's
 * EFFECTIVE DELIVERY CITY (city of the selected/default address). Each
 * branch is listed independently; branches of other cities are never
 * returned (404 when addressed directly). Listings eager-load everything
 * they render (no N+1). Delivery coverage/distance is checked at checkout.
 *
 * Prices are stored in the default currency; when the customer chose a
 * display currency, a converted `display` amount is added (informational —
 * the payable amount is fixed at checkout).
 */
class CustomerCatalogService
{
    protected ?Currency $default = null;

    public function __construct(protected BranchHoursService $hours) {}

    public function deliveryCity(User $user): City
    {
        $city = $user->addresses()->where('is_default', true)->with('city')->first()?->city;
        if (! $city || ! $city->is_active) {
            throw new BusinessRuleException(__('messages.customer.no_delivery_address'), 409);
        }

        return $city;
    }

    /** Active branches of active stores in the city. */
    public function branchQuery(City $city): Builder
    {
        return Branch::query()->where('city_id', $city->id)->where('is_active', true)
            ->whereHas('store', fn ($query) => $query->where('is_active', true));
    }

    public function branchInCity(User $user, int|string $branchId): Branch
    {
        $branch = $this->branchQuery($this->deliveryCity($user))->whereKey($branchId)
            ->with(['store.categories', 'openingHours', 'hourExceptions' => fn ($q) => $q->where('ends_at', '>', now())->orderBy('starts_at')])->first();
        if (! $branch) {
            throw new BusinessRuleException(__('messages.customer.not_in_your_city'), 404);
        }

        return $branch;
    }

    public function storeCategories(): Collection
    {
        return StoreCategory::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'image_url' => $this->url($c->image)]);
    }

    public function productCategories(): Collection
    {
        $all = Category::query()->where('is_active', true)->orderBy('sort_order')->get();
        $node = function ($category) use (&$node, $all) {
            return [
                'id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'image_url' => $this->url($category->image),
                'children' => $all->where('parent_id', $category->id)->map($node)->values(),
            ];
        };

        return $all->whereNull('parent_id')->map($node)->values();
    }

    public function branches(User $user, array $filters, int $perPage)
    {
        $page = $this->branchQuery($this->deliveryCity($user))
            ->when($filters['store_category_id'] ?? null, fn ($q, $id) => $q->whereHas('store.categories', fn ($c) => $c->whereKey($id)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => JsonSearch::apply($w, 'name', $term)
                ->orWhereHas('store', fn ($s) => JsonSearch::apply($s, 'name', $term))))
            ->with(['store.categories', 'openingHours', 'hourExceptions' => fn ($q) => $q->where('ends_at', '>', now())])
            ->orderBy('id')->paginate($perPage);

        $page->setCollection($page->getCollection()->map(fn ($b) => $this->branchSummary($b)));

        return $page;
    }

    public function branchSummary(Branch $branch): array
    {
        $now = now();

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'lat' => (float) $branch->lat, 'lng' => (float) $branch->lng,
            'store' => [
                'id' => $branch->store->id, 'name' => $branch->store->name, 'logo_url' => $this->url($branch->store->logo),
                'categories' => $branch->store->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            ],
            'is_open_now' => $this->hours->isOpenAt($branch, $now),
            'is_accepting_orders' => (bool) $branch->is_accepting_orders,
            'min_order' => $branch->min_order_amount !== null ? $this->price($branch->min_order_amount, null) : null,
        ];
    }

    public function branchDetail(User $user, int|string $branchId): array
    {
        $branch = $this->branchInCity($user, $branchId);
        $categoryIds = BranchProduct::query()->where('branch_id', $branch->id)->where('is_available', true)
            ->join('product_category', 'product_category.product_id', '=', 'branch_products.product_id')
            ->distinct()->pluck('product_category.category_id');

        return $this->branchSummary($branch) + [
            'phone' => $branch->phone,
            'opening_hours' => $branch->openingHours->sortBy(['day_of_week', 'opens_at'])->map(fn ($h) => [
                'day_of_week' => $h->day_of_week, 'opens_at' => substr((string) $h->opens_at, 0, 5), 'closes_at' => substr((string) $h->closes_at, 0, 5),
            ])->values(),
            'exceptions' => $branch->hourExceptions->map(fn ($e) => [
                'kind' => $e->kind, 'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'reason' => $e->reason,
            ])->values(),
            'product_categories' => Category::query()->whereIn('id', $categoryIds)->orderBy('sort_order')->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ];
    }

    public function branchProducts(User $user, int|string $branchId, array $filters, int $perPage)
    {
        $branch = $this->branchInCity($user, $branchId);
        $page = BranchProduct::query()->where('branch_id', $branch->id)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('product.categories', fn ($c) => $c->whereKey($id)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereHas('product', fn ($p) => JsonSearch::apply($p, 'name', $term)))
            ->when(! ($filters['include_unavailable'] ?? false), fn ($q) => $q->where('is_available', true))
            ->with(['product.categories'])->orderBy('id')->paginate($perPage);

        $offers = $this->offersFor($branch);
        $page->setCollection($page->getCollection()->map(fn ($bp) => $this->productSummary($bp, $offers->get($bp->product_id, collect()), $user)));

        return $page;
    }

    public function productDetail(User $user, int|string $branchId, int|string $productId): array
    {
        $branch = $this->branchInCity($user, $branchId);
        $bp = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $productId)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->with(['product.categories', 'product.optionGroups' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'), 'product.optionGroups.options' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'), 'options'])
            ->first();
        if (! $bp) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }
        $branchOptions = $bp->options->keyBy('product_option_id');

        return $this->productSummary($bp, $this->offersFor($branch)->get($bp->product_id, collect()), $user) + [
            'description' => $bp->product->description,
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'store' => ['id' => $branch->store->id, 'name' => $branch->store->name]],
            'option_groups' => $bp->product->optionGroups->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'kind' => $g->kind, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
                'options' => $g->options->filter(fn ($o) => $branchOptions->has($o->id))->map(fn ($o) => [
                    'id' => $o->id, 'name' => $o->name, 'max_quantity' => $o->max_quantity,
                    'price_delta' => $this->price($branchOptions[$o->id]->price_delta, $user),
                    'is_available' => (bool) $branchOptions[$o->id]->is_available,
                ])->values(),
            ])->values(),
        ];
    }

    public function search(User $user, string $term, int $limit = 20): array
    {
        $city = $this->deliveryCity($user);
        $branches = $this->branchQuery($city)->where(fn ($w) => JsonSearch::apply($w, 'name', $term)
            ->orWhereHas('store', fn ($s) => JsonSearch::apply($s, 'name', $term)))
            ->with(['store.categories', 'openingHours', 'hourExceptions' => fn ($q) => $q->where('ends_at', '>', now())])->limit($limit)->get();

        $products = BranchProduct::query()->where('is_available', true)
            ->whereIn('branch_id', $this->branchQuery($city)->select('id'))
            ->whereHas('product', fn ($p) => JsonSearch::apply($p->where('is_active', true), 'name', $term))
            ->with(['product.categories', 'branch.store'])->limit($limit)->get();
        $offers = Offer::query()->currentlyActive()->whereIn('store_id', $products->pluck('store_id')->unique())->whereNotNull('product_id')->get();

        return [
            'branches' => $branches->map(fn ($b) => $this->branchSummary($b))->values(),
            'products' => $products->map(fn ($bp) => $this->productSummary($bp, $offers->where('product_id', $bp->product_id)->filter(fn ($o) => $o->branch_id === null || $o->branch_id === $bp->branch_id), $user) + [
                'branch' => ['id' => $bp->branch->id, 'name' => $bp->branch->name, 'store' => ['id' => $bp->branch->store->id, 'name' => $bp->branch->store->name]],
            ])->values(),
        ];
    }

    public function offers(User $user): Collection
    {
        $city = $this->deliveryCity($user);
        $branchIds = $this->branchQuery($city)->pluck('id');
        $storeIds = Branch::query()->whereIn('id', $branchIds)->distinct()->pluck('store_id');

        return Offer::query()->currentlyActive()->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->with(['store', 'branch', 'product'])->orderBy('ends_at')->get()
            ->map(fn ($o) => [
                'id' => $o->id, 'name' => $o->name, 'discount_type' => $o->discount_type,
                'discount_value' => $o->discount_type === 'percent' ? (string) $o->discount_value : $this->price($o->discount_value, $user),
                'starts_at' => $o->starts_at->toIso8601String(), 'ends_at' => $o->ends_at?->toIso8601String(),
                'store' => ['id' => $o->store->id, 'name' => $o->store->name],
                'branch' => $o->branch ? ['id' => $o->branch->id, 'name' => $o->branch->name] : null,
                'product' => $o->product ? ['id' => $o->product->id, 'name' => $o->product->name, 'image_url' => $this->url($o->product->image)] : null,
            ])->values();
    }

    /** Product card at a branch: branch price, best current public offer, aggregate rating. */
    public function productSummary(BranchProduct $bp, Collection $offers, ?User $user): array
    {
        $product = $bp->product;
        $price = BigDecimal::of((string) $bp->price);
        $best = null;
        $bestDiscount = BigDecimal::zero();
        foreach ($offers as $offer) {
            $discount = $offer->discount_type === 'percent'
                ? $price->multipliedBy((string) $offer->discount_value)->dividedBy(100, 4, RoundingMode::HALF_UP)
                : BigDecimal::of((string) $offer->discount_value);
            if ($discount->isGreaterThan($bestDiscount)) {
                [$best, $bestDiscount] = [$offer, $discount->isGreaterThan($price) ? $price : $discount];
            }
        }

        return [
            'id' => $product->id,
            'branch_product_id' => $bp->id,
            'name' => $product->name,
            'image_url' => $this->url($product->image),
            'categories' => $product->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'price' => $this->price($bp->price, $user),
            'offer' => $best ? ['id' => $best->id, 'name' => $best->name, 'price_after_offer' => $this->price((string) $price->minus($bestDiscount), $user)] : null,
            'is_available' => (bool) $bp->is_available,
            'rating' => $this->rating($product),
        ];
    }

    /** Genuine aggregate only (hidden ratings are already excluded from the counters); placeholder kept separate. */
    public function rating(Product $product): array
    {
        static $placeholder = false;
        if ($placeholder === false) {
            $value = Setting::query()->where('key', 'catalog.placeholder_rating')->value('value');
            $placeholder = is_numeric($value) ? (float) $value : null;
        }

        return ['average' => $product->averageRating(), 'count' => (int) $product->rating_count, 'placeholder' => $product->rating_count > 0 ? null : $placeholder];
    }

    /** @return Collection<int, Collection<int, Offer>> active product offers of the branch, keyed by product */
    public function offersFor(Branch $branch): Collection
    {
        return Offer::query()->currentlyActive()->where('store_id', $branch->store_id)->whereNotNull('product_id')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->get()->groupBy('product_id');
    }

    /** A default-currency amount, plus its display-currency conversion when the customer chose one. */
    public function price(BigDecimal|string|null $amount, ?User $user): array
    {
        $this->default ??= Currency::query()->default()->first();
        $value = BigDecimal::of((string) ($amount ?? '0'));
        $display = $user?->displayCurrency;

        return [
            'amount' => (string) Money::roundTo($value, $this->default?->decimals ?? 2),
            'currency' => $this->default?->code,
            'display' => $display && $display->id !== $this->default?->id ? [
                'amount' => (string) Money::roundTo($value->multipliedBy((string) $display->exchange_rate), $display->decimals),
                'currency' => $display->code,
            ] : null,
        ];
    }

    public function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
