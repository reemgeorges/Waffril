<?php

namespace App\Http\Middleware;

use App\Support\Time\AdminTimezone;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WAFFRLI (2026-11-17) — admin panel display timezone (see AdminTimezone).
 * Registered as panel middleware AND Livewire persistent middleware, so
 * full page loads and every Livewire update (filters, actions, realtime
 * re-renders, wire:navigate) use the same zone. Display only: the app
 * timezone (UTC) and stored values are untouched.
 */
class SetAdminDisplayTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        FilamentTimezone::set(AdminTimezone::resolveFrom($request));

        return $next($request);
    }
}
