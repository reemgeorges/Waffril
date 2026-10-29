<?php

namespace App\Http\Traits;

trait CanFilterResourceKeys
{
    /**
     * Keep only specific keys in a resolved resource.
     *
     * @param \Illuminate\Http\Resources\Json\ResourceCollection|\Illuminate\Support\Collection $resource
     * @param array $keysToKeep
     * @return \Illuminate\Support\Collection
     */
    public function keepOnlyKeysFromResource($resource, array $keysToKeep)
    {
        $resolved = $resource->resolve();

        return collect($resolved)->map(function ($item) use ($keysToKeep) {
            return collect($keysToKeep)->reduce(function ($carry, $key) use ($item) {
                $value = data_get($item, $key);
                if (!is_null($value)) {
                    data_set($carry, $key, $value);
                }
                return $carry;
            }, []);
        });
    }
}
