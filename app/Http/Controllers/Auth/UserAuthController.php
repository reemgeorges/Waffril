<?php

namespace App\Http\Controllers\Auth;

use App\Facades\Services\Auth\UserAuthFacade;
use App\Http\Controllers\FatherAuthController;
use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;

class UserAuthController extends FatherAuthController
{
    use GeneralTrait;

    /**
     * اربط الكونترولر مع خدمة المستخدم عبر الـ Facade
     */
    protected function setVariables(): void
    {
        $this->key = 'user';
        $this->service = UserAuthFacade::class;
    }

    /**
     * تسجيل/تفعيل عبر السوشال (اختياري)
     * يتعامل معه UserAuthService::socialRegister
     */
    public function socialRegister(Request $request)
    {
        try {
            $data = $this->service::socialRegister($request);
            return $this->apiResponse($data, true);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * تسجيل خروج + فصل device_token (اختياري)
     */
    public function logout(Request $request)
    {
        try {
            $deviceToken = $request->input('token_device');
            $user = $request->user('user');

            if ($user) {
                $user->currentAccessToken()?->delete();
            }

            if ($deviceToken) {
                // نفصل ربط الجهاز عبر الخدمة
                $this->service::revokeDeviceToken($request);
            }

            return $this->apiResponse(['message' => __('messages.logged_out')]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
