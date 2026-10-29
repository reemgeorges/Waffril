<?php

namespace App\Services\Functional;

use App\Http\Resources\Model\AdminResource;
use App\Http\Resources\Model\UserResource;
use App\Http\Traits\GeneralTrait;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 3C (2026-10-25) — "review unused ... CombinedAuthService ...
 * safely." Not routed anywhere (no facade, no controller, no route —
 * confirmed by grep); the real login flows are
 * App\Services\Functional\{Admin,User}AuthService. Hardened as defense in
 * depth in case it is ever wired to a route later:
 *  - Previously issued a token to a deactivated or soft-deleted account —
 *    neither branch checked is_active (soft-deleted rows were already
 *    excluded by Eloquent's default query scope, but is_active was not).
 *  - The error response used a raw response()->json([...], 422) instead
 *    of this app's standard {data, status, error, statusCode} envelope —
 *    inconsistent with every other auth error in the app.
 *  - Tokens now carry a guard-tagged ability, matching
 *    BaseAuthService::issueToken()'s convention, instead of the
 *    unqualified default.
 */
class CombinedAuthService
{
    use GeneralTrait;

    public function login(Request $request): array
    {
        $email = $request->input('email');
        $password = $request->input('password');

        $admin = Admin::where('email', $email)->first();
        if ($admin && $admin->password && Hash::check($password, $admin->password)) {
            if (! $admin->is_active) {
                throw new HttpResponseException($this->requiredField(__('messages.account_inactive')));
            }

            $token = $admin->createToken('access-token', ['admin', '*'])->plainTextToken;
            $admin->loadMissing(['roles', 'permissions']);

            return ['token' => $token, 'admin' => AdminResource::make($admin)];
        }

        $user = User::where('email', $email)->first();
        if ($user && $user->password && Hash::check($password, $user->password)) {
            if (! $user->is_active) {
                throw new HttpResponseException($this->requiredField(__('messages.account_inactive')));
            }

            $token = $user->createToken('access-token', ['user', '*'])->plainTextToken;

            return ['token' => $token, 'user' => UserResource::make($user)];
        }

        throw new HttpResponseException($this->requiredField(__('messages.invalid_credentials')));
    }
}
