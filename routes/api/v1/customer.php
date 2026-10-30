<?php

use App\Http\Controllers\City\CustomerCityController;
use App\Http\Controllers\Customer\CustomerAddressController;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\CustomerCartController;
use App\Http\Controllers\Customer\CustomerCatalogController;
use App\Http\Controllers\Customer\CustomerEngagementController;
use App\Http\Controllers\Customer\CustomerOrderController;
use App\Http\Controllers\Customer\CustomerPlusController;
use App\Http\Controllers\Customer\CustomerProfileController;
use App\Http\Controllers\Customer\CustomerWalletController;
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

        // Carts (one per branch) and checkout.
        Route::get('carts', [CustomerCartController::class, 'index']);
        Route::get('carts/{cart}', [CustomerCartController::class, 'show'])->whereNumber('cart');
        Route::delete('carts/{cart}', [CustomerCartController::class, 'clear'])->whereNumber('cart');
        Route::post('cart/items', [CustomerCartController::class, 'addItem']);
        Route::patch('cart/items/{item}', [CustomerCartController::class, 'updateItem'])->whereNumber('item');
        Route::delete('cart/items/{item}', [CustomerCartController::class, 'removeItem'])->whereNumber('item');
        Route::post('carts/{cart}/checkout', [CustomerCartController::class, 'checkout'])->whereNumber('cart')->middleware('throttle:customer-checkout');

        // Orders.
        Route::get('orders', [CustomerOrderController::class, 'index']);
        Route::get('orders/{order}', [CustomerOrderController::class, 'show'])->whereNumber('order');
        Route::post('orders/{order}/cancel', [CustomerOrderController::class, 'cancel'])->whereNumber('order');
        Route::post('orders/{order}/cancellation-request', [CustomerOrderController::class, 'requestCancellation'])->whereNumber('order');

        // Wallet, payments, currencies (read-only).
        Route::get('wallet', [CustomerWalletController::class, 'wallets']);
        Route::get('wallet/transactions', [CustomerWalletController::class, 'transactions']);
        Route::get('payments', [CustomerWalletController::class, 'payments']);
        Route::get('refunds', [CustomerWalletController::class, 'refunds']);
        Route::get('currencies', [CustomerWalletController::class, 'currencies']);

        // WAFFRLI PLUS.
        Route::get('plus/plans', [CustomerPlusController::class, 'plans']);
        Route::get('plus/membership', [CustomerPlusController::class, 'membership']);
        Route::get('plus/eligible-discounts', [CustomerPlusController::class, 'eligibleDiscounts']);
        Route::get('plus/requests', [CustomerPlusController::class, 'requests']);
        Route::post('plus/requests', [CustomerPlusController::class, 'submit'])->middleware('throttle:10,1');

        // __CUSTOMER_ROUTES_BATCH_7__
    });
});
