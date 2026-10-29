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


            return redirect()->guest(route('login'));
        });
    }
}
