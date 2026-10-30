<?php

namespace App\Http\Middleware;

use App\Http\Traits\GeneralTrait;
use App\Models\Driver;
use Closure;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02). A deactivated or soft-deleted driver
 * keeps no access to protected driver operations, even with a token issued
 * earlier (deactivation through Filament also revokes tokens; this is the
 * second line of defence). Use after `auth:driver` + `guard.identity:driver`.
 */
class EnsureDriverIsActive
{
    use GeneralTrait;

    public function handle(Request $request, Closure $next)
    {
        $driver = $request->user('driver');

        if (! $driver instanceof Driver || ! $driver->is_active || $driver->trashed()) {
            return $this->forbiddenResponse(__('messages.driver.account_inactive'));
        }

        return $next($request);
    }
}
