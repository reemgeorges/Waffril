<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;



return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
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
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            // Phase 2A (2026-10-25): see App\Http\Middleware\EnsureGuardIdentity.
            'guard.identity'     => \App\Http\Middleware\EnsureGuardIdentity::class,

        ]);


        $middleware->append(\App\Http\Middleware\ForceJsonResponse::class);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        return new \App\Exceptions\Handler(app());
    })
    ->withProviders([
        \App\Providers\ApiExceptionServiceProvider::class,
        // Phase 2B (2026-10-25): fills in guard/provider config for any
        // auth module that doesn't have one hand-written in config/auth.php.
        \App\Providers\AuthModulesServiceProvider::class,
        // OwenIt\Auditing\AuditingServiceProvider::class,
    ])
    ->withCommands([

    ])
    ->create();
