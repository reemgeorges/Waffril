<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Phase 2B (2026-10-25).
 *
 * Reads config/auth_modules.php and fills in any guard/provider that is not
 * already defined in config/auth.php. This is deliberately additive-only
 * ("fill gaps", never overwrite): the Phase 2A `admin`/`user` guards are
 * already hand-written in config/auth.php and are left completely alone —
 * this provider only matters for a module `make:auth-module` generates
 * from scratch later (e.g. "agent", "customer"), which has no
 * config/auth.php entry at all. That is what makes "configurable, not
 * hardcoded" (CLAUDE.md target architecture) actually true for new
 * modules: config/auth.php never needs hand-editing again after this.
 *
 * Runs in register() (not boot()) so the merged config is visible to every
 * other provider's boot() — including Spatie Permission's guard_name
 * resolution, which reads config('auth.guards')/config('auth.providers').
 */
class AuthModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $modules = config('auth_modules.modules', []);

        foreach ($modules as $name => $module) {
            $guard = $module['guard'] ?? $name;
            $provider = $module['provider'] ?? "{$name}s";
            $model = $module['model'] ?? null;

            if (! $model) {
                continue;
            }

            if (! config("auth.providers.{$provider}")) {
                config(["auth.providers.{$provider}" => [
                    'driver' => 'eloquent',
                    'model' => $model,
                ]]);
            }

            if (! config("auth.guards.{$guard}")) {
                config(["auth.guards.{$guard}" => [
                    'driver' => 'sanctum',
                    'provider' => $provider,
                ]]);
            }
        }
    }
}
