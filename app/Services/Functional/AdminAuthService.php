<?php

namespace App\Services\Functional;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\AdminResource;
use App\Models\Admin;
use App\Services\Basic\BaseAuthService;
use App\Support\OtpChannelHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;

class AdminAuthService extends BaseAuthService
{
    protected function setVariables(): void
    {
        $this->model    = Admin::class;
        $this->key      = 'admin';
        $this->resource = AdminResource::class;
        $this->guard    = 'admin';
    }

    public function login(BasicRequest $request): array
    {
        $email  = $request->input('email');
        $pass   = $request->input('password');

        $admin = $this->model::where('email', $email)->first();
        if (!$admin || !$admin->password || !Hash::check($pass, $admin->password)) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_credentials')));
        }

        // Phase 2A (2026-10-25): deactivated admins cannot authenticate.
        // Soft-deleted admins are already excluded above (same reasoning as
        // UserAuthService::login()).
        if (!$admin->is_active) {
            throw new HttpResponseException($this->requiredField(__('messages.account_inactive')));
        }

        $admin->loadMissing(['roles', 'permissions']);

        return $this->completeLogin($admin, $request);
    }

    public function verifyOtp(BasicRequest $request): array
    {
        if ($request->purpose === 'phone_change') {
            return $this->verifyPhoneChangeOtp($request);
        }

        if ($request->purpose === 'email_change') {
            return $this->verifyEmailChangeOtp($request);
        }

        $admin = $this->model::where('email', $request->input('email'))->firstOrFail();
        $channel = 'email';

        $ok = OtpChannelHelper::verify($admin, $request->input('purpose'), (string) $request->input('otp'), $channel);
        if (!$ok) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
        }

        return [
            'message' => '',
            $this->key => $this->resource::make($admin->fresh()),
        ];
    }
}
