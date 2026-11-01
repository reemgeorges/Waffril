<?php

namespace App\Services\Model\City;

use App\Contracts\Delivery\DistanceCalculator;
use App\Http\Resources\Model\CityResource;
use App\Models\City;
use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;

/**
 * WAFFRLI P0 (2026-10-30). Admin CRUD for delivery cities (inherited
 * from BasicCrudService) plus the two customer-facing reads: the active
 * city list and GPS city detection.
 */
class CityService extends BasicCrudService
{
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = City::class
        );

        $this->resource = CityResource::class;
    }

    public function active(): mixed
    {
        return $this->resource::collection(
            City::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    /**
     * Nearest active city whose center lies within its own radius_km of
     * the given position, or null when the position is outside every
     * service city.
     *
     * @return array{city: mixed, distance_km: float, distance_method: string}|null
     */
    public function detect(float $lat, float $lng): ?array
    {
        $distance = app(DistanceCalculator::class);
        $best = null;
        $bestKm = null;

        foreach (City::query()->where('is_active', true)->get() as $city) {
            $km = $distance->distanceKm((float) $city->center_lat, (float) $city->center_lng, $lat, $lng);
            if ($km <= (float) $city->radius_km && ($bestKm === null || $km < $bestKm)) {
                $best = $city;
                $bestKm = $km;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'city' => $this->resource::make($best),
            'distance_km' => $bestKm,
            'distance_method' => $distance->method(),
        ];
    }
}
