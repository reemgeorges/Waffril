<?php

use App\Exceptions\Handler;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomerIsActive;
use App\Http\Middleware\EnsureDriverIsActive;
use App\Http\Middleware\EnsureGuardIdentity;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetRequestLocale;
use App\Providers\ApiExceptionServiceProvider;
use App\Providers\AuthModulesServiceProvider;
use App\Support\Time\AdminTimezone;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            foreach (glob(base_path('routes/api/v1/*.php')) as $file) {

                Route::middleware('api')
                    ->prefix('api/v1')
                    ->group($file);
            }

        }

    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // WAFFRLI (2026-11-17): the admin's browser timezone is written by the
        // panel's own script (not secret; validated against the IANA list).
        $middleware->encryptCookies(except: [AdminTimezone::COOKIE]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            // Phase 2A (2026-10-25): see App\Http\Middleware\EnsureGuardIdentity.
            'guard.identity' => EnsureGuardIdentity::class,

            // WAFFRLI driver API (2026-11-02).
            'driver.active' => EnsureDriverIsActive::class,

            // WAFFRLI customer API (2026-11-03).
            'customer.active' => EnsureCustomerIsActive::class,

        ]);

        // WAFFRLI (2026-11-16): correlation id (log context, error monitor,
        // X-Request-Id response header). First, so every layer sees it.
        $middleware->prepend(AssignRequestId::class);

        $middleware->append(ForceJsonResponse::class);

        // WAFFRLI P0 (2026-10-30): ar/en locale from Accept-Language for API
        // routes; without the header the configured locale is unchanged.
        $middleware->appendToGroup('api', SetRequestLocale::class);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        return new Handler(app());
    })
    ->withProviders([
        ApiExceptionServiceProvider::class,
        // Phase 2B (2026-10-25): fills in guard/provider config for any
        // auth module that doesn't have one hand-written in config/auth.php.
        AuthModulesServiceProvider::class,
        // OwenIt\Auditing\AuditingServiceProvider::class,
    ])
    ->withCommands([

    ])
    ->create();
