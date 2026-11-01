<?php

namespace App\Http\Resources\Model;

use App\Http\Resources\Basic\BasicResource;
use App\Models\Currency;
use App\Services\Basic\ModelColumnsService;
use Illuminate\Http\Request;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class CurrencyResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        return $this->initResource(
            ModelColumnsService::getServiceFor(
                Currency::class
            )
        );
    }

    protected function initResource($modelColumnsService): array
    {
        $this->result = parent::initResource($modelColumnsService);

        return array_merge($this->result, [
            'name_translations' => $this->resource->getTranslations('name'),
            'is_default' => (bool) $this->resource->is_default,
        ]);
    }
}
