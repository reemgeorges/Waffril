<?php

namespace App\Services\Model\Currency;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\CurrencyResource;
use App\Http\Traits\GeneralTrait;
use App\Models\Currency;
use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI P0 (2026-10-30). Currency management rules:
 *
 * - Every non-default rate means "units per 1 default unit", so a
 *   non-default currency can only be created once a default exists. The
 *   very first currency is the only one that may exist without a default
 *   (it is the candidate to initialize).
 * - initializeDefault() works only while NO default exists. Switching an
 *   existing default is prohibited in P0 (reserved for the P7
 *   re-denomination job behind `currencies.change_default`).
 * - The default currency's rate is always exactly 1 and it can never be
 *   deactivated. `code` is immutable once created.
 */
class CurrencyService extends BasicCrudService
{
    use GeneralTrait;

    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = Currency::class
        );

        $this->resource = CurrencyResource::class;
    }

    public function create(BasicRequest $request): mixed
    {
        $this->object = DB::transaction(function () use ($request) {
            $hasDefault = Currency::query()->default()->lockForUpdate()->exists();
            if (! $hasDefault && Currency::query()->exists()) {
                $this->fail(__('messages.currency_default_required_first'));
            }

            $data = $request->validated();
            $data['code'] = strtoupper($data['code']);
            $data['rate_updated_at'] = now();
            $data['rate_updated_by_admin_id'] = $request->user()?->getKey();

            return Currency::create($data);
        });

        return $this->resource::make($this->object->fresh());
    }

    public function update(BasicRequest $request): mixed
    {
        $this->object = DB::transaction(function () use ($request) {
            $currency = Currency::query()->lockForUpdate()->findOrFail($request->input('id'));
            $data = $request->validated();
            unset($data['id']);

            if ($currency->is_default) {
                if (array_key_exists('exchange_rate', $data) && ! BigDecimal::of((string) $data['exchange_rate'])->isEqualTo(1)) {
                    $this->fail(__('messages.currency_default_rate_fixed'));
                }
                if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                    $this->fail(__('messages.currency_default_cannot_deactivate'));
                }
                unset($data['exchange_rate']);
            }

            if (array_key_exists('exchange_rate', $data)) {
                $data['rate_updated_at'] = now();
                $data['rate_updated_by_admin_id'] = $request->user()?->getKey();
            }

            $currency->update($data);

            return $currency;
        });

        return $this->resource::make($this->object->fresh());
    }

    public function deactivate(Request $request): bool
    {
        return DB::transaction(function () use ($request) {
            $this->object = Currency::query()->lockForUpdate()->findOrFail($request->input('id'));
            if ($this->object->is_default) {
                $this->fail(__('messages.currency_default_cannot_deactivate'));
            }

            return $this->object->update(['is_active' => false]);
        });
    }

    /**
     * One-time bootstrap of the platform's first default currency. The
     * unique default_marker index guarantees at most one default even
     * under concurrency; the explicit check gives a clear error instead.
     */
    public function initializeDefault(Request $request): mixed
    {
        $this->object = DB::transaction(function () use ($request) {
            if (Currency::query()->default()->lockForUpdate()->exists()) {
                $this->fail(__('messages.currency_default_already_initialized'), 409);
            }

            $currency = Currency::query()->lockForUpdate()->findOrFail($request->input('id'));

            $currency->forceFill([
                'is_default' => true,
                'is_active' => true,
                'exchange_rate' => '1',
                'rate_updated_at' => now(),
                'rate_updated_by_admin_id' => $request->user()?->getKey(),
            ])->save();

            return $currency;
        });

        return $this->resource::make($this->object->fresh());
    }

    protected function fail(string $message, int $status = 422): never
    {
        throw new HttpResponseException($this->apiResponse(null, false, $message, $status));
    }
}
