<?php

namespace App\Http\Controllers\Customer;

use App\Http\Requests\Model\Customer\CustomerAddressRequest;
use App\Http\Resources\Model\Customer\CustomerAddressResource;
use App\Services\Customer\CustomerProfileService;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03) — the customer's own addresses (others → 404). */
class CustomerAddressController extends CustomerController
{
    public function __construct(protected CustomerProfileService $profiles) {}

    public function index(Request $request)
    {
        return $this->respond(fn () => ['addresses' => CustomerAddressResource::collection($this->customer($request)->addresses()->with('city')->orderByDesc('is_default')->orderBy('id')->get())]);
    }

    public function store(CustomerAddressRequest $request)
    {
        return $this->respond(fn () => ['message' => __('messages.customer.address_saved'), 'address' => CustomerAddressResource::make($this->profiles->saveAddress($this->customer($request), $request->validated()))]);
    }

    public function update(CustomerAddressRequest $request, string $address)
    {
        return $this->respond(function () use ($request, $address) {
            $user = $this->customer($request);

            return ['message' => __('messages.customer.address_saved'), 'address' => CustomerAddressResource::make($this->profiles->saveAddress($user, $request->validated(), $this->profiles->ownAddress($user, $address)))];
        });
    }

    public function destroy(Request $request, string $address)
    {
        return $this->respond(function () use ($request, $address) {
            $user = $this->customer($request);
            $this->profiles->deleteAddress($user, $this->profiles->ownAddress($user, $address));

            return ['message' => __('messages.customer.address_deleted')];
        });
    }

    public function makeDefault(Request $request, string $address)
    {
        return $this->respond(function () use ($request, $address) {
            $user = $this->customer($request);

            return ['address' => CustomerAddressResource::make($this->profiles->setDefault($user, $this->profiles->ownAddress($user, $address)))];
        });
    }
}
