<?php

namespace App\Http\Middleware;

use App\Http\Traits\GeneralTrait;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * WAFFRLI customer API (2026-11-03). Suspended, deactivated, deleted or
 * anonymized customers keep no access to protected operations, even with a
 * token issued earlier. Use after `auth:user` + `guard.identity:user`.
 */
class EnsureCustomerIsActive
{
    use GeneralTrait;

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('user');

        if (! $user instanceof User || $user->trashed() || $user->anonymized_at !== null) {
            return $this->unAuthorizeResponse(__('messages.customer.account_closed'));
        }
        if ($user->isSuspended() || ! $user->is_active) {
            return $this->forbiddenResponse(__('messages.customer.account_suspended'));
        }

        return $next($request);
    }
}
