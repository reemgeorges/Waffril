<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\BusinessRuleException;
use App\Services\Currency\CurrencyPolicy;
use App\Services\Customer\CartService;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\CheckoutService;
use App\Support\Money\Denomination;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — carts (one per branch) and checkout. */
class CustomerCartController extends CustomerController
{
    public function __construct(protected CartService $carts) {}

    public function index(Request $request)
    {
        return $this->respond(fn () => ['carts' => $this->carts->carts($this->customer($request))]);
    }

    public function show(Request $request, string $cart)
    {
        return $this->respond(function () use ($request, $cart) {
            $user = $this->customer($request);

            return ['cart' => $this->carts->present($user, $this->carts->cart($user, $cart))];
        });
    }

    public function addItem(Request $request)
    {
        return $this->respond(function () use ($request) {
            $data = $request->validate([
                'branch_id' => ['required', 'integer'],
                'product_id' => ['required', 'integer'],
                'quantity' => ['required', 'integer', 'between:1,99'],
                'options' => ['nullable', 'array', 'max:30'],
                'options.*.id' => ['required', 'integer'],
                'options.*.quantity' => ['nullable', 'integer', 'between:1,20'],
                'notes' => ['nullable', 'string', 'max:255'],
            ]);
            $options = collect($data['options'] ?? [])->mapWithKeys(fn ($o) => [(int) $o['id'] => (int) ($o['quantity'] ?? 1)])->all();

            return ['message' => __('messages.customer.cart_updated'), 'cart' => $this->carts->addItem($this->customer($request), (int) $data['branch_id'], (int) $data['product_id'], (int) $data['quantity'], $options, $data['notes'] ?? null)];
        });
    }

    public function updateItem(Request $request, string $item)
    {
        return $this->respond(function () use ($request, $item) {
            $data = $request->validate(['quantity' => ['required', 'integer', 'between:0,99'], 'notes' => ['nullable', 'string', 'max:255']]);

            return ['message' => __('messages.customer.cart_updated'), 'cart' => $this->carts->updateItem($this->customer($request), $item, (int) $data['quantity'], $data['notes'] ?? null)];
        });
    }

    public function removeItem(Request $request, string $item)
    {
        return $this->respond(fn () => ['message' => __('messages.customer.cart_updated'), 'cart' => $this->carts->updateItem($this->customer($request), $item, 0, null)]);
    }

    public function clear(Request $request, string $cart)
    {
        return $this->respond(function () use ($request, $cart) {
            $this->carts->clear($this->customer($request), $cart);

            return ['message' => __('messages.customer.cart_cleared')];
        });
    }

    /** Idempotent: send the same `Idempotency-Key` header (or checkout_key) to safely retry. */
    public function checkout(Request $request, string $cart)
    {
        return $this->respond(function () use ($request, $cart) {
            $data = $request->validate([
                'checkout_key' => ['nullable', 'uuid'],
                'address_id' => ['nullable', 'integer'],
                'currency_id' => ['nullable', 'integer'],
                'wallet_amount' => ['nullable', 'string', 'regex:/^(max|\d{1,14}(\.\d{1,4})?)$/'],
                'use_free_delivery' => ['nullable', 'boolean'],
                'scheduled_for' => ['nullable', 'date'],
                'notes' => ['nullable', 'string', 'max:500'],
            ]);
            $key = $request->header('Idempotency-Key') ?: ($data['checkout_key'] ?? null);
            if (! $key || ! preg_match('/^[0-9a-fA-F-]{36}$/', $key)) {
                throw new BusinessRuleException(__('validation.required', ['attribute' => 'Idempotency-Key (uuid)']), 422);
            }
            $user = $this->customer($request);
            // wallet_amount is typed in the DISPLAYED denomination (new SYP, up
            // to 2 decimals); the services work in stored units (old SYP).
            if (isset($data['wallet_amount']) && $data['wallet_amount'] !== 'max' && $data['wallet_amount'] !== '') {
                $currency = CurrencyPolicy::paymentCurrency($user, isset($data['currency_id']) ? (int) $data['currency_id'] : null);
                $stored = Denomination::toStored($data['wallet_amount'], $currency);
                if ($stored === null) {
                    throw new BusinessRuleException(__('validation.decimal', ['attribute' => 'wallet_amount', 'decimal' => Denomination::decimals($currency)]), 422);
                }
                $data['wallet_amount'] = (string) $stored;
            }
            $result = app(CheckoutService::class)->checkoutCart($user, $cart, ['checkout_key' => strtolower($key)] + $data);

            return [
                'message' => __('messages.customer.order_placed'),
                'replayed' => $result['replayed'],
                'order' => app(CustomerOrderService::class)->present($result['order']->load(CustomerOrderService::WITH)),
            ];
        });
    }
}
