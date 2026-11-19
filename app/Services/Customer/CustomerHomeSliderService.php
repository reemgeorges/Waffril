<?php

namespace App\Services\Customer;

use App\Models\HomeSlider;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only app home slides, scoped to the same effective delivery city
 * as the rest of the customer catalog. No external URL redirect is supported.
 */
class CustomerHomeSliderService
{
    public function __construct(protected CustomerCatalogService $catalog) {}

    public function list(User $customer): Collection
    {
        $city = $this->catalog->deliveryCity($customer);

        return HomeSlider::query()
            ->published()
            ->where(function ($query) use ($city) {
                $query->whereNull('store_id')
                    ->orWhereHas('store', fn ($store) => $store->where('is_active', true)
                        ->whereHas('branches', fn ($branch) => $branch
                            ->where('city_id', $city->id)
                            ->where('is_active', true)));
            })
            ->with(['store' => fn ($query) => $query->with(['branches' => fn ($branch) => $branch
                ->where('city_id', $city->id)
                ->where('is_active', true)
                ->orderBy('id')])])
            ->orderBy('sort_order')->orderBy('id')
            ->limit(30)
            ->get()
            ->map(function (HomeSlider $slider) {
                $store = $slider->store;
                $branch = $store?->branches?->first();

                return [
                    'id' => $slider->id,
                    'title' => $slider->title,
                    'subtitle' => $slider->subtitle,
                    'image_url' => Storage::disk('public')->url($slider->image),
                    'action' => $branch ? 'store' : 'none',
                    'store' => $branch ? [
                        'id' => $store->id,
                        'name' => $store->name,
                        'branch_id' => $branch->id,
                    ] : null,
                ];
            });
    }
}
