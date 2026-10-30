<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;
use App\Support\Geo\Polygon;
use Closure;

/**
 * WAFFRLI P0 (2026-10-30). `boundary` is a GeoJSON Polygon; the
 * bounding-box columns are derived server-side and cannot be sent.
 */
class StoreDeliveryAreaRequest extends BasicRequest
{
    use TranslatableNameRules;

    public function rules(): array
    {
        return array_merge([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'boundary' => ['required', 'array', $this->boundaryRule()],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'min_lat' => ['prohibited'],
            'max_lat' => ['prohibited'],
            'min_lng' => ['prohibited'],
            'max_lng' => ['prohibited'],
        ], $this->nameRules());
    }

    protected function boundaryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($error = Polygon::validationError($value)) {
                $fail($error);
            }
        };
    }
}
