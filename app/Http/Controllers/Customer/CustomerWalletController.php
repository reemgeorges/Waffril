<?php

namespace App\Http\Controllers\Customer;

use App\Models\Currency;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Localization\Label;
use App\Support\Money\Money;
use Illuminate\Http\Request;

/**
 * WAFFRLI customer API (2026-11-03) — READ-ONLY money views. Wallets are
 * per currency and are funded only by refunds and payment reversals; there
 * is no top-up, withdrawal or conversion endpoint.
 */
class CustomerWalletController extends CustomerController
{
    public function wallets(Request $request)
    {
        return $this->respond(fn () => ['wallets' => Wallet::query()->where('user_id', $this->customer($request)->id)->with('currency')->get()->map(fn ($w) => [
            'currency' => $w->currency->code, 'symbol' => $w->currency->symbol,
            'balance' => (string) Money::roundTo((string) $w->balance, $w->currency->decimals),
        ])->values()]);
    }

    public function transactions(Request $request)
    {
        return $this->respond(function () use ($request) {
            $page = WalletTransaction::query()->whereHas('wallet', fn ($q) => $q->where('user_id', $this->customer($request)->id)
                ->when($request->query('currency'), fn ($c, $code) => $c->whereHas('currency', fn ($cc) => $cc->where('code', strtoupper($code)))))
                ->with(['wallet.currency', 'order'])->orderByDesc('id')->paginate($this->perPage($request));

            return $this->paginated($page, 'transactions', fn ($t) => [
                'id' => $t->id, 'type' => $t->type, 'type_label' => Label::of('wallet_type', $t->type),
                'direction' => $t->direction, 'direction_label' => Label::of('wallet_direction', $t->direction), 'currency' => $t->wallet->currency->code,
                'amount' => (string) $t->amount, 'balance_after' => (string) $t->balance_after,
                'order_number' => $t->order?->order_number, 'created_at' => $t->created_at?->toIso8601String(),
            ]);
        });
    }

    public function payments(Request $request)
    {
        return $this->respond(function () use ($request) {
            $page = OrderPayment::query()->whereHas('order', fn ($q) => $q->where('user_id', $this->customer($request)->id))
                ->with(['order', 'method', 'currency'])->orderByDesc('id')->paginate($this->perPage($request));

            return $this->paginated($page, 'payments', fn ($p) => [
                'order_number' => $p->order->order_number, 'method' => $p->method->code, 'method_label' => $p->method->name, 'currency' => $p->currency->code,
                'amount' => (string) $p->amount, 'status' => $p->status, 'status_label' => Label::of('payment_status', $p->status), 'collected' => (string) $p->collected_amount,
                'reversed' => (string) $p->reversed_amount, 'refunded' => (string) $p->refunded_amount, 'paid_at' => $p->paid_at?->toIso8601String(),
            ]);
        });
    }

    public function refunds(Request $request)
    {
        return $this->respond(function () use ($request) {
            $page = OrderRefund::query()->whereHas('order', fn ($q) => $q->where('user_id', $this->customer($request)->id))
                ->with(['order', 'currency'])->orderByDesc('id')->paginate($this->perPage($request));

            return $this->paginated($page, 'refunds', fn ($r) => [
                'order_number' => $r->order->order_number, 'currency' => $r->currency->code, 'amount' => (string) $r->amount,
                'reason' => $r->reason_code, 'reason_label' => Label::of('cancellation_reason', $r->reason_code), 'credited_to' => 'wallet', 'created_at' => $r->created_at?->toIso8601String(),
            ]);
        });
    }

    public function currencies()
    {
        return $this->respond(fn () => ['currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get()->map(fn ($c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol, 'decimals' => $c->decimals,
            'exchange_rate' => (string) $c->exchange_rate, 'is_default' => (bool) $c->is_default,
        ])->values()]);
    }
}
