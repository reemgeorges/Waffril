<?php

namespace App\Services\Model\Currency;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\CurrencyResource;
use App\Models\Admin;
use App\Models\Currency;
use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use App\Services\Currency\CurrencyManagement;
use Illuminate\Http\Request;

/**
 * WAFFRLI P0 (2026-10-30). Admin currency API service.
 *
 * Since 2026-10-31 every rule lives in App\Services\Currency\CurrencyManagement
 * (shared with the Filament panel); this class only adapts HTTP requests
 * to it. Rule violations throw App\Exceptions\BusinessRuleException, which
 * GeneralTrait renders in the API envelope with the same status codes as
 * before (422, or 409 for a second default initialization).
 */
class CurrencyService extends BasicCrudService
{
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = Currency::class
        );

        $this->resource = CurrencyResource::class;
    }

    protected function management(): CurrencyManagement
    {
        return app(CurrencyManagement::class);
    }

    protected function actor(Request $request): ?Admin
    {
        $user = $request->user();

        return $user instanceof Admin ? $user : null;
    }

    public function create(BasicRequest $request): mixed
    {
        $this->object = $this->management()->create($request->validated(), $this->actor($request));

        return $this->resource::make($this->object->fresh());
    }

    public function update(BasicRequest $request): mixed
    {
        $currency = Currency::findOrFail($request->input('id'));
        $this->object = $this->management()->update($currency, $request->validated(), $this->actor($request));

        return $this->resource::make($this->object->fresh());
    }

    public function activate(Request $request): bool
    {
        $this->object = $this->management()->setActive(Currency::findOrFail($request->input('id')), true, $this->actor($request));

        return true;
    }

    public function deactivate(Request $request): bool
    {
        $this->object = $this->management()->setActive(Currency::findOrFail($request->input('id')), false, $this->actor($request));

        return true;
    }

    public function initializeDefault(Request $request): mixed
    {
        $this->object = $this->management()->initializeDefault(Currency::findOrFail($request->input('id')), $this->actor($request));

        return $this->resource::make($this->object->fresh());
    }
}
