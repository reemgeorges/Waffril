<?php

namespace App\Providers;

use App\Http\Traits\GeneralTrait;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

class ApiExceptionServiceProvider extends ServiceProvider
{
    use GeneralTrait;

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        app(ExceptionHandler::class)->renderable(function (AuthenticationException $e, $request) {

            if ($request->is('api/*') || $request->expectsJson()) {
                return $this->unAuthorizeResponse();
            }

            // WAFFRLI (2026-10-31): honour the guard's own login URL (the
            // Filament admin panel sets one); fall back to the legacy named route.
            return redirect()->guest($e->redirectTo($request) ?? route('login'));
        });
    }
}
