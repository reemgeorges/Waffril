<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\User;
use App\Services\Order\OrderPricingService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WAFFRLI customer API (2026-11-03) — one cart per (customer, branch);
 * a customer may have several carts (one per branch). Every line is
 * validated with the same rules as checkout (OrderPricingService) when it
 * is added; identical configurations are merged into one line.
 */
class CartService
{
    public function __construct(protected OrderPricingService $pricing, protected CustomerCatalogService $catalog) {}

    public function carts(User $user)
    {
        return Cart::query()->where('user_id', $user->id)->with(['branch.store', 'items.branchProduct.product', 'items.options.group'])->orderByDesc('updated_at')->get()
            ->map(fn (Cart $cart) => $this->present($user, $cart))->values();
    }

    public function cart(User $user, int|string $cartId): Cart
    {
        $cart = Cart::query()->whereKey($cartId)->where('user_id', $user->id)->first();
        if (! $cart) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }

        return $cart;
    }

    /** @param array<int|string, int> $options product_option_id => quantity */
    public function addItem(User $user, int $branchId, int $productId, int $quantity, array $options, ?string $notes): array
    {
        $branch = $this->catalog->branchInCity($user, $branchId);
        $bp = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $productId)
            ->with(['product.optionGroups.options', 'options'])->first();
        if (! $bp || ! $bp->is_available || ! $bp->product?->is_active) {
            throw new BusinessRuleException(__('messages.customer.item_unavailable'), 422);
        }
        $this->pricing->resolveOptions($bp, $options);

        $cart = DB::transaction(function () use ($user, $branch, $bp, $quantity, $options, $notes) {
            $cart = Cart::query()->firstOrCreate(['user_id' => $user->id, 'branch_id' => $branch->id]);
            $hash = OrderPricingService::configurationHash($bp->id, $options);
            $item = CartItem::query()->where('cart_id', $cart->id)->where('branch_product_id', $bp->id)->where('configuration_hash', $hash)->lockForUpdate()->first();
            if ($item) {
                $item->update(['quantity' => min(99, $item->quantity + $quantity), 'notes' => $notes ?? $item->notes]);
            } else {
                $item = CartItem::create(['cart_id' => $cart->id, 'branch_product_id' => $bp->id, 'quantity' => $quantity, 'configuration_hash' => $hash, 'notes' => $notes]);
                $item->options()->sync(collect($options)->mapWithKeys(fn ($qty, $id) => [(int) $id => ['quantity' => (int) $qty]])->all());
            }
            $cart->touch();

            return $cart;
        });

        return $this->present($user, $cart->fresh());
    }

    public function updateItem(User $user, int|string $itemId, int $quantity, ?string $notes): array
    {
        $item = CartItem::query()->whereKey($itemId)->whereHas('cart', fn ($q) => $q->where('user_id', $user->id))->first();
        if (! $item) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }
        $cart = $item->cart;
        if ($quantity === 0) {
            $item->delete();
        } else {
            $item->update(['quantity' => $quantity] + ($notes !== null ? ['notes' => $notes] : []));
        }
        $cart->touch();

        return $this->present($user, $cart->fresh());
    }

    public function clear(User $user, int|string $cartId): void
    {
        $this->cart($user, $cartId)->delete();
    }

    /** Cart with lines and a live server-side price preview (never trusted at checkout: re-priced there). */
    public function present(User $user, Cart $cart): array
    {
        $cart->loadMissing(['branch.store', 'items.branchProduct.product', 'items.options.group']);
        $lines = $cart->items->map(fn ($i) => [
            'branch_product_id' => $i->branch_product_id, 'quantity' => $i->quantity,
            'options' => $i->options->mapWithKeys(fn ($o) => [$o->id => (int) $o->pivot->quantity])->all(), 'notes' => $i->notes,
        ])->all();

        $preview = null;
        $issue = null;
        if ($lines !== []) {
            try {
                $address = $user->addresses()->where('is_default', true)->first();
                $currency = ($user->display_currency_id ? Currency::query()->whereKey($user->display_currency_id)->where('is_active', true)->first() : null) ?? Currency::query()->default()->firstOrFail();
                $priced = $this->pricing->price($user, $cart->branch, $lines, $currency, $address, false); // free delivery is only applied when requested at checkout
                $fmt = fn ($v) => (string) Money::roundTo($v, $currency->decimals);
                $preview = [
                    'currency' => $currency->code,
                    'subtotal' => $fmt($priced['subtotal']), 'offer_discount' => $fmt($priced['offer_discount_total']),
                    'plus_discount' => $fmt($priced['plus_discount_amount']), 'delivery_fee' => $fmt($priced['delivery_fee']),
                    'delivery_fee_original' => $fmt($priced['delivery_fee_original']), 'free_delivery_available' => $priced['free_delivery_available'],
                    'delivery_covered' => $priced['delivery_covered'], 'meets_min_order' => $priced['meets_min_order'], 'total' => $fmt($priced['total']),
                ];
            } catch (Throwable $e) {
                $issue = $e->getMessage();
            }
        }

        return [
            'id' => $cart->id,
            'branch' => ['id' => $cart->branch->id, 'name' => $cart->branch->name, 'store' => ['id' => $cart->branch->store->id, 'name' => $cart->branch->store->name]],
            'items' => $cart->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->branchProduct->product_id, 'name' => $i->branchProduct->product->name,
                'image_url' => $this->catalog->url($i->branchProduct->product->image), 'quantity' => $i->quantity, 'notes' => $i->notes,
                'is_available' => (bool) $i->branchProduct->is_available && (bool) $i->branchProduct->product->is_active,
                'options' => $i->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'group' => $o->group?->name, 'quantity' => (int) $o->pivot->quantity])->values(),
            ])->values(),
            'preview' => $preview,
            'issue' => $issue,
            'updated_at' => $cart->updated_at?->toIso8601String(),
        ];
    }
}
