<?php

use App\Http\Controllers\Driver\DriverAuthController;
use App\Http\Controllers\Driver\DriverAvailabilityController;
use App\Http\Controllers\Driver\DriverCashController;
use App\Http\Controllers\Driver\DriverLocationController;
use App\Http\Controllers\Driver\DriverNotificationController;
use App\Http\Controllers\Driver\DriverOrderController;
use Illuminate\Support\Facades\Route;

/*
 * WAFFRLI driver API (2026-11-02) — /api/v1/driver/*.
 *
 * Only `login` is public (throttled). Everything else requires a DRIVER
 * Sanctum token: `auth:driver` + `guard.identity:driver` (customer and
 * admin tokens are rejected) + `driver.active` (deactivated or deleted
 * drivers lose access). Drivers are created by admins; there is no
 * self-registration route.
 */
Route::prefix('driver')->group(function () {
    Route::post('login', [DriverAuthController::class, 'login'])->middleware('throttle:driver-login');

    Route::middleware(['auth:driver', 'guard.identity:driver', 'driver.active', 'throttle:driver-api'])->group(function () {
        Route::post('logout', [DriverAuthController::class, 'logout']);
        Route::get('me', [DriverAuthController::class, 'me']);
        Route::patch('profile', [DriverAuthController::class, 'updateProfile']);
        Route::post('password', [DriverAuthController::class, 'changePassword']);

        Route::get('availability', [DriverAvailabilityController::class, 'show']);
        Route::patch('availability', [DriverAvailabilityController::class, 'update']);

        Route::get('orders/active', [DriverOrderController::class, 'active']);
        Route::get('orders/history', [DriverOrderController::class, 'history']);
        Route::get('orders/{order}', [DriverOrderController::class, 'show'])->whereNumber('order');
        Route::post('orders/{order}/pickup', [DriverOrderController::class, 'pickup'])->whereNumber('order');
        Route::post('orders/{order}/deliver', [DriverOrderController::class, 'deliver'])->whereNumber('order');
        Route::post('orders/{order}/problems', [DriverOrderController::class, 'reportProblem'])->whereNumber('order');

        Route::post('location', [DriverLocationController::class, 'update'])->middleware('throttle:driver-location');

        Route::get('cash/summary', [DriverCashController::class, 'summary']);
        Route::get('cash/collections', [DriverCashController::class, 'collections']);
        Route::get('cash/settlements', [DriverCashController::class, 'settlements']);

        Route::get('notifications', [DriverNotificationController::class, 'index']);
        Route::post('notifications/read', [DriverNotificationController::class, 'markManyRead']);
        Route::post('notifications/{notification}/read', [DriverNotificationController::class, 'markRead'])->whereUuid('notification');
    });
});
