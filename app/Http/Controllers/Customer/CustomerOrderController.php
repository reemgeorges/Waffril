<?php

namespace App\Http\Controllers\Customer;

use App\Services\Customer\CustomerOrderService;
use App\Support\Localization\Label;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — own orders, cancellation and cancellation requests. */
class CustomerOrderController extends CustomerController
{
    public function __construct(protected CustomerOrderService $orders) {}

    public function index(Request $request)
    {
        return $this->respond(function () use ($request) {
            $scope = in_array($request->query('scope'), ['current', 'previous'], true) ? $request->query('scope') : 'all';
            $page = $this->orders->list($this->customer($request), $scope, $this->perPage($request));

            return $this->paginated($page, 'orders', fn ($o) => $this->orders->present($o, false));
        });
    }

    public function show(Request $request, string $order)
    {
        return $this->respond(fn () => ['order' => $this->orders->present($this->orders->own($this->customer($request), $order))]);
    }

    public function cancel(Request $request, string $order)
    {
        return $this->respond(function () use ($request, $order) {
            $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

            return ['message' => __('messages.customer.order_cancelled'), 'order' => $this->orders->present($this->orders->cancel($this->customer($request), $order, $note))];
        });
    }

    public function requestCancellation(Request $request, string $order)
    {
        return $this->respond(function () use ($request, $order) {
            $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
            $req = $this->orders->requestCancellation($this->customer($request), $order, $note);

            return ['message' => __('messages.customer.cancel_request_sent'), 'request' => ['id' => $req->id, 'status_label' => Label::of('request_status', $req->fresh()->status), 'status' => $req->fresh()->status]];
        });
    }
}
