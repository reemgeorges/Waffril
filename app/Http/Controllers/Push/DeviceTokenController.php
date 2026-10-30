<?php

namespace App\Http\Controllers\Push;

use App\Http\Controllers\Controller;
use App\Http\Requests\Model\Push\DeviceTokenRequest;
use App\Http\Resources\Model\Notification\DeviceTokenResource;
use App\Http\Traits\GeneralTrait;
use App\Services\Push\DeviceTokenService;
use Illuminate\Http\Request;

/**
 * WAFFRLI (2026-11-03) — FCM device tokens of the signed-in customer or
 * driver (the route's guard decides). Another account's token is 404.
 */
class DeviceTokenController extends Controller
{
    use GeneralTrait;

    public function index(Request $request)
    {
        try {
            return $this->apiResponse(['device_tokens' => DeviceTokenResource::collection(app(DeviceTokenService::class)->list($request->user()))]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function store(DeviceTokenRequest $request)
    {
        try {
            $token = app(DeviceTokenService::class)->register($request->user(), $request->validated());

            return $this->apiResponse(['message' => __('messages.push.device_registered'), 'device_token' => DeviceTokenResource::make($token)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function update(DeviceTokenRequest $request, string $deviceToken)
    {
        try {
            $token = app(DeviceTokenService::class)->update($request->user(), $deviceToken, $request->validated());

            return $this->apiResponse(['message' => __('messages.push.device_updated'), 'device_token' => DeviceTokenResource::make($token)]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function destroy(Request $request, string $deviceToken)
    {
        try {
            app(DeviceTokenService::class)->remove($request->user(), $deviceToken);

            return $this->apiResponse(['message' => __('messages.push.device_removed')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
