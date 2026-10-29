<?php

namespace App\Services\Functional;

use App\Http\Requests\Basic\BasicRequest;
use App\Http\Resources\Model\UserResource;
use App\Models\User;
use App\Services\Basic\BaseAuthService;
use App\Support\OtpChannelHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;

class UserAuthService extends BaseAuthService
{
    protected function setVariables(): void
    {
        $this->model    = User::class;
        $this->key      = 'user';
        $this->resource = UserResource::class;
        $this->guard    = 'user';
    }

    public function login(BasicRequest $request): array
    {
        $email  = $request->input('email');
        $pass   = $request->input('password');
        $pp     = $request->input('prefix_phone');
        $pn     = $request->input('phone_number');
        $social = $request->input('social_id');

        // Phase 3B (2026-10-25) — CRITICAL SECURITY FIX: this branch
        // previously authenticated anyone who sent an existing user's
        // social_id as a bare request field — no password, no OAuth token
        // verification of any kind. Anyone who knew or guessed a
        // social_id could log in as that user. Disabled until backed by a
        // real, verified OAuth identity (Phase 3C+): `social_id` is still
        // stored/declared, but it can never authenticate on its own.
        if ($social && !$pass && !$email && !$pp && !$pn) {
            throw new HttpResponseException($this->requiredField(__('messages.social_login_not_supported')));
        }

        if ($email && $pass) {
            $user = $this->model::where('email', $email)->first();
        } elseif ($pp && $pn && $pass) {
            $user = $this->model::where('prefix_phone', $pp)->where('phone_number', $pn)->first();
        } else {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_credentials')));
        }

        if (!$user || !$user->password || !Hash::check($pass, $user->password)) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_credentials')));
        }

        // Phase 2A (2026-10-25): deactivated accounts cannot authenticate.
        // Soft-deleted users are already excluded above (Eloquent's default
        // SoftDeletes query scope hides deleted_at rows unless withTrashed()
        // is used, and this query never calls it), so no separate trashed()
        // check is needed here.
        if (!$user->is_active) {
            throw new HttpResponseException($this->requiredField(__('messages.account_inactive')));
        }

        if (!$user->status) {
            OtpChannelHelper::send($user, 'register', $user->otp_delivery_method ?? 'email');

            return [
                'token' => null,
                $this->key => $this->resource::make($user),
                'need_verify' => true,
            ];
        }

        return array_merge($this->completeLogin($user, $request), ['need_verify' => false]);
    }

    public function verifyOtp(BasicRequest $request): array
    {
        if ($request->purpose === 'phone_change') {
            return $this->verifyPhoneChangeOtp($request);
        }

        if ($request->purpose === 'email_change') {
            return $this->verifyEmailChangeOtp($request);
        }

        $col = $request->filled('id') ? 'id' : 'email';
        $user = $this->model::where($col, $request->input($col))->firstOrFail();
        $channel = $user->otp_delivery_method ?? 'email';

        $ok = OtpChannelHelper::verify($user, $request->input('purpose'), (string) $request->input('otp'), $channel);
        if (!$ok) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
        }

        if (in_array($channel, ['sms', 'whatsapp'])) {
            $user->phone_status = true;
            $user->phone_verified_at = now();
        } else {
            $user->email_status = true;
            $user->email_verified_at = now();
        }

        $user->status = true;
        $user->activated_at = now();
        $user->save();

        $token = null;
        if ($request->filled('id') && $user->id === $request->input('id')) {
            [$token] = $this->issueToken($user, $request->input('token_device'));
        }

        return [
            'message' => 'Your account has been verified successfully.',
            'token' => $token,
            $this->key => $this->resource::make($user->fresh()),
        ];
    }
}
