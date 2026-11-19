<?php

namespace App\Http\Controllers\Customer;

use App\Services\Customer\CustomerHomeSliderService;
use Illuminate\Http\Request;

/** Authenticated, read-only homepage slideshow API. */
class CustomerHomeSliderController extends CustomerController
{
    public function __invoke(Request $request, CustomerHomeSliderService $sliders)
    {
        return $this->respond(fn () => ['sliders' => $sliders->list($this->customer($request))]);
    }
}
