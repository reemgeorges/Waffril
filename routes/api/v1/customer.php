<?php

use App\Http\Controllers\City\CustomerCityController;
use App\Http\Controllers\Customer\CustomerAddressController;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\CustomerCatalogController;
use App\Http\Controllers\Customer\CustomerEngagementController;
use App\Http\Controllers\Customer\CustomerProfileController;
use Illuminate\Support\Facades\Route;

/*
 * WAFFRLI customer API (2026-11-03) — /api/v1/customer/*.
 *
 * Public: phone-OTP login/registration and account recovery (throttled).
 * Everything else: `auth:user` + `guard.identity:user` (driver/admin tokens
 * rejected) + `customer.active` (suspended/deleted customers rejected).
 */
Route::prefix('customer')->group(function () {
    // Sending codes: per-IP and per-phone limits (plus OTP cooldown/send caps in PhoneOtpService).
    Route::middleware('throttle:customer-otp')->group(function () {
        Route::post('auth/otp/request', [CustomerAuthController::class, 'requestOtp']);
        Route::post('auth/recovery/request', [CustomerAuthController::class, 'requestRecovery']);
    });
    // Checking codes: per-IP limit here; per-phone verify caps and per-code attempt ceilings in PhoneOtpService.
    Route::middleware('throttle:customer-otp-verify')->group(function () {
        Route::post('auth/otp/verify', [CustomerAuthController::class, 'verifyOtp']);
        Route::post('auth/register', [CustomerAuthController::class, 'register']);
        Route::post('auth/recovery/verify', [CustomerAuthController::class, 'recover']);
    });

    Route::middleware(['auth:user', 'guard.identity:user', 'customer.active', 'throttle:customer-api'])->group(function () {
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);

        Route::get('me', [CustomerProfileController::class, 'show']);
        Route::patch('me', [CustomerProfileController::class, 'update']);
        Route::post('me/avatar', [CustomerProfileController::class, 'uploadAvatar']);
        Route::delete('me/avatar', [CustomerProfileController::class, 'deleteAvatar']);
        Route::post('me/email/verification', [CustomerProfileController::class, 'sendEmailVerification'])->middleware('throttle:6,1');
        Route::post('me/email/verify', [CustomerProfileController::class, 'verifyEmail'])->middleware('throttle:10,1');
        Route::delete('me', [CustomerProfileController::class, 'requestDeletion']);
        Route::get('context', [CustomerProfileController::class, 'context']);

        Route::get('addresses', [CustomerAddressController::class, 'index']);
        Route::post('addresses', [CustomerAddressController::class, 'store']);
        Route::patch('addresses/{address}', [CustomerAddressController::class, 'update'])->whereNumber('address');
        Route::delete('addresses/{address}', [CustomerAddressController::class, 'destroy'])->whereNumber('address');
        Route::post('addresses/{address}/default', [CustomerAddressController::class, 'makeDefault'])->whereNumber('address');

        Route::get('cities', [CustomerCityController::class, 'active']);
        Route::post('cities/detect', [CustomerCityController::class, 'detect'])->middleware('throttle:60,1');

        // Catalog (delivery city of the selected address only).
        Route::get('store-categories', [CustomerCatalogController::class, 'storeCategories']);
        Route::get('product-categories', [CustomerCatalogController::class, 'productCategories']);
        Route::get('branches', [CustomerCatalogController::class, 'branches']);
        Route::get('branches/{branch}', [CustomerCatalogController::class, 'branch'])->whereNumber('branch');
        Route::get('branches/{branch}/products', [CustomerCatalogController::class, 'branchProducts'])->whereNumber('branch');
        Route::get('branches/{branch}/products/{product}', [CustomerCatalogController::class, 'product'])->whereNumber(['branch', 'product']);
        Route::get('search', [CustomerCatalogController::class, 'search'])->middleware('throttle:60,1');
        Route::get('offers', [CustomerCatalogController::class, 'offers']);

        // Favorites and ratings (products only).
        Route::get('favorites', [CustomerEngagementController::class, 'favorites']);
        Route::post('products/{product}/favorite', [CustomerEngagementController::class, 'addFavorite'])->whereNumber('product');
        Route::delete('products/{product}/favorite', [CustomerEngagementController::class, 'removeFavorite'])->whereNumber('product');
        Route::post('products/{product}/rating', [CustomerEngagementController::class, 'rate'])->whereNumber('product');

        // __CUSTOMER_ROUTES_BATCH_4__
    });
});
