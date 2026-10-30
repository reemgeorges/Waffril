<?php

namespace App\Http\Controllers\City;

use App\Facades\Services\City\CityFacade;
use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Platform\DetectCityRequest;
use App\Http\Traits\GeneralTrait;

/**
 * WAFFRLI P0 (2026-10-30). Customer-facing city reads, behind
 * auth:user + guard.identity:user (guest access would need an explicit
 * opt-in decision).
 */
class CustomerCityController extends Controller
{
    use GeneralTrait;

    public function active()
    {
        try {
            return $this->apiResponse(['cities' => CityFacade::active()]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function detect()
    {
        try {
            $request = app(DetectCityRequest::class);
            $result = CityFacade::detect((float) $request->input('lat'), (float) $request->input('lng'));

            if ($result === null) {
                return $this->notFoundResponse(__('messages.city_not_served'));
            }

            return $this->apiResponse($result);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
