<?php


use App\Http\Controllers\Auth\AdminAuthController;

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {

    Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
    });

    // Phase 3C (2026-10-25): throttled — see
    // App\Providers\AppServiceProvider::registerAuthRateLimiters().
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:auth-login');

    // Phase 3B (2026-10-25): reset password, covered end-to-end by
    // tests/Feature/AuthFlowsIntegrationTest.php. verify-otp issues a
    // single-use, short-lived reset_token (PasswordResetGrantService);
    // reset requires and consumes it — no Sanctum token is issued by this
    // flow, unlike the separate (unrouted) ResetPasswordService.
    Route::post('password/send-otp', [AdminAuthController::class, 'sendResetOtp'])->middleware('throttle:auth-otp-endpoint');
    Route::post('password/verify-otp', [AdminAuthController::class, 'verifyResetOtp'])->middleware('throttle:auth-otp-endpoint');
    Route::post('password/reset', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:auth-otp-endpoint');

    // Phase 3C (2026-10-25): safe to register unconditionally — see
    // routes/api/v1/user.php's matching route for why (the
    // otpActionAllowed('login_challenge') gate inside
    // BaseAuthService::verifyLoginChallenge() is what actually makes this
    // safe-by-default, not the route's absence).
    Route::post('login-challenge/verify', [AdminAuthController::class, 'verifyLoginChallenge'])->middleware('throttle:auth-otp-endpoint');

    // Phase 2A (2026-10-25): 'guard.identity:admin' closes the gap where a
    // valid User sanctum token would otherwise also satisfy 'auth:admin'
    // (see App\Http\Middleware\EnsureGuardIdentity doc comment).
    Route::middleware(['auth:admin', 'guard.identity:admin'])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);

        // Phase 3C (2026-10-25): the first routes to actually enforce
        // app/Policies/UserPolicy.php (existed since Phase 2B, never
        // wired to anything until now). No role is granted by default —
        // see App\Http\Controllers\Admin\UserManagementController's doc
        // comment.
        Route::get('users/all', [\App\Http\Controllers\Admin\UserManagementController::class, 'all']);
        Route::post('users/activate', [\App\Http\Controllers\Admin\UserManagementController::class, 'activate']);
        Route::post('users/deactivate', [\App\Http\Controllers\Admin\UserManagementController::class, 'deactivate']);
    });

    // SECURITY (Phase 0.1, 2026-10-25): this `test` CRUD block (including
    // create/update/activate/deactivate) sat inside the `admin` prefix group
    // but OUTSIDE the `auth:admin` middleware group above — i.e. it looked
    // like an admin-protected path but was fully unauthenticated. Same root
    // cause as the Phase 0 routes/api.php defect. There is still no working
    // `admin` guard registered in config/auth.php (see config/auth.php —
    // only `web` is defined), so attaching `auth:admin` here would throw
    // ("Auth guard [admin] is not defined") rather than secure it. Disabled
    // (not publicly reachable) until Phase 2 introduces a real, tested
    // `admin` guard.
    // TODO(phase-2): re-enable behind a real auth:admin guard + authorization
    // policy once the admin guard exists, then restore the block below
    // verbatim (nested inside the auth:admin middleware group this time).
    // Route::prefix('test')->group(function () {
    //     Route::get('/all/paginated', [\App\Http\Controllers\Test\TestController::class, 'allPaginated']);
    //     Route::get('/all',           [\App\Http\Controllers\Test\TestController::class, 'all']);
    //     Route::post('/show',         [\App\Http\Controllers\Test\TestController::class, 'show']);
    //     Route::post('/create',       [\App\Http\Controllers\Test\TestController::class, 'store']);
    //     Route::post('/update',       [\App\Http\Controllers\Test\TestController::class, 'update']);
    //     Route::post('/activate',     [\App\Http\Controllers\Test\TestController::class, 'activate']);
    //     Route::post('/deactivate',   [\App\Http\Controllers\Test\TestController::class, 'deactivate']);
    // });

});

