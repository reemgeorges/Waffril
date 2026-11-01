<?php

namespace App\Exceptions;

use App\Http\Traits\GeneralTrait;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    use GeneralTrait;

    protected $levels = [];

    protected $dontReport = [];

    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    public function render($request, Throwable $e)
    {
        // ✅ اعتبر أي مسار يبدأ بـ api/* طلب JSON حتى لو ما فيه Accept
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->handleException($e);
        }

        return parent::render($request, $e);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->unAuthorizeResponse();
        }

        // WAFFRLI (2026-10-31): honour the guard's own login URL (the
        // Filament admin panel sets one); fall back to the legacy named route.
        return redirect()->guest($exception->redirectTo($request) ?? route('login'));
    }
}
