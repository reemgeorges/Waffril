<?php

use App\Http\Controllers\City\CityController;
use App\Http\Controllers\City\CustomerCityController;
use App\Http\Controllers\Currency\CurrencyController;
use App\Http\Controllers\DeliveryArea\DeliveryAreaController;
use App\Http\Controllers\DeliveryFeeRule\DeliveryFeeRuleController;
use App\Http\Controllers\Setting\SettingController;
use App\Models\City;
use App\Models\Currency;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;

/*
 * WAFFRLI P0 Foundation (2026-10-30).
 *
 * Every admin route requires a valid ADMIN Sanctum token
 * (auth:admin + guard.identity:admin) AND the model's `manage` policy
 * ability, which maps to one explicitly granted admin permission (see
 * App\Support\Auth\PlatformPermissions). Nothing here is public: the two
 * customer routes require a customer token. No route deletes anything,
 * and there is deliberately no route that switches an existing default
 * currency (P7).
 */
Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'guard.identity:admin'])->group(function () {

    Route::prefix('settings')->name('settings.')->middleware('can:manage,'.Setting::class)->group(function () {
        Route::get('/all/paginated', [SettingController::class, 'allPaginated'])->name('paginated');
        Route::get('/all', [SettingController::class, 'all'])->name('all');
        Route::post('/show', [SettingController::class, 'show'])->name('show');
        Route::post('/create', [SettingController::class, 'store'])->name('create');
        Route::post('/update', [SettingController::class, 'update'])->name('update');
    });

    Route::prefix('currencies')->name('currencies.')->middleware('can:manage,'.Currency::class)->group(function () {
        Route::get('/all/paginated', [CurrencyController::class, 'allPaginated'])->name('paginated');
        Route::get('/all', [CurrencyController::class, 'all'])->name('all');
        Route::post('/show', [CurrencyController::class, 'show'])->name('show');
        Route::post('/create', [CurrencyController::class, 'store'])->name('create');
        Route::post('/update', [CurrencyController::class, 'update'])->name('update');
        Route::post('/activate', [CurrencyController::class, 'activate'])->name('activate');
        Route::post('/deactivate', [CurrencyController::class, 'deactivate'])->name('deactivate');
        Route::post('/initialize-default', [CurrencyController::class, 'initializeDefault'])->name('initialize_default');
    });

    Route::prefix('cities')->name('cities.')->middleware('can:manage,'.City::class)->group(function () {
        Route::get('/all/paginated', [CityController::class, 'allPaginated'])->name('paginated');
        Route::get('/all', [CityController::class, 'all'])->name('all');
        Route::post('/show', [CityController::class, 'show'])->name('show');
        Route::post('/create', [CityController::class, 'store'])->name('create');
        Route::post('/update', [CityController::class, 'update'])->name('update');
        Route::post('/activate', [CityController::class, 'activate'])->name('activate');
        Route::post('/deactivate', [CityController::class, 'deactivate'])->name('deactivate');
    });

    Route::prefix('delivery-areas')->name('delivery_areas.')->middleware('can:manage,'.DeliveryArea::class)->group(function () {
        Route::get('/all/paginated', [DeliveryAreaController::class, 'allPaginated'])->name('paginated');
        Route::get('/all', [DeliveryAreaController::class, 'all'])->name('all');
        Route::post('/show', [DeliveryAreaController::class, 'show'])->name('show');
        Route::post('/create', [DeliveryAreaController::class, 'store'])->name('create');
        Route::post('/update', [DeliveryAreaController::class, 'update'])->name('update');
        Route::post('/activate', [DeliveryAreaController::class, 'activate'])->name('activate');
        Route::post('/deactivate', [DeliveryAreaController::class, 'deactivate'])->name('deactivate');
    });

    Route::prefix('delivery-fee-rules')->name('delivery_fee_rules.')->middleware('can:manage,'.DeliveryFeeRule::class)->group(function () {
        Route::get('/all/paginated', [DeliveryFeeRuleController::class, 'allPaginated'])->name('paginated');
        Route::get('/all', [DeliveryFeeRuleController::class, 'all'])->name('all');
        Route::post('/show', [DeliveryFeeRuleController::class, 'show'])->name('show');
        Route::post('/create', [DeliveryFeeRuleController::class, 'store'])->name('create');
        Route::post('/update', [DeliveryFeeRuleController::class, 'update'])->name('update');
        Route::post('/activate', [DeliveryFeeRuleController::class, 'activate'])->name('activate');
        Route::post('/deactivate', [DeliveryFeeRuleController::class, 'deactivate'])->name('deactivate');
        Route::post('/quote', [DeliveryFeeRuleController::class, 'quote'])->name('quote');
    });
});

Route::prefix('user')->name('user.')->middleware(['auth:user', 'guard.identity:user'])->group(function () {
    Route::get('cities', [CustomerCityController::class, 'active'])->name('cities.active');
    Route::post('cities/detect', [CustomerCityController::class, 'detect'])->name('cities.detect')->middleware('throttle:60,1');
});
