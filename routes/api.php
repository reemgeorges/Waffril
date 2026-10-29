<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// SECURITY (Phase 0, 2026-10-25): The route groups below exposed full CRUD
// (including create/update/activate/deactivate) for User, Test and Category
// with ZERO authentication middleware. Combined with BasicRequest::authorize()
// returning true unconditionally, any anonymous caller could create or mutate
// arbitrary records, including User rows carrying a client-supplied
// `email_verified_at`. There is currently no working `admin`/`user` auth guard
// registered in config/auth.php, so attaching `auth:admin` / `auth:user` here
// would either throw ("Auth guard [admin] is not defined") or give a false
// sense of security. These routes are therefore disabled (not publicly
// reachable) until Phase 2 introduces real, tested guards.
// TODO(phase-2): re-enable behind a real auth guard + authorization policy
// once admin/user guards exist, then restore the route groups below verbatim.

// User PUBLIC ROUTES — DISABLED, see note above.
// Route::prefix('user')->group(function () {
//     Route::get('/all/paginated', [\App\Http\Controllers\User\UserController::class, 'allPaginated']);
//     Route::get('/all',           [\App\Http\Controllers\User\UserController::class, 'all']);
//     Route::post('/show',         [\App\Http\Controllers\User\UserController::class, 'show']);
//     Route::post('/create',       [\App\Http\Controllers\User\UserController::class, 'store']);
//     Route::post('/update',       [\App\Http\Controllers\User\UserController::class, 'update']);
//     Route::post('/activate',     [\App\Http\Controllers\User\UserController::class, 'activate']);
//     Route::post('/deactivate',   [\App\Http\Controllers\User\UserController::class, 'deactivate']);
// });

// Test PUBLIC ROUTES — DISABLED, see note above.
// Route::prefix('test')->group(function () {
//     Route::get('/all/paginated', [\App\Http\Controllers\Test\TestController::class, 'allPaginated']);
//     Route::get('/all',           [\App\Http\Controllers\Test\TestController::class, 'all']);
//     Route::post('/show',         [\App\Http\Controllers\Test\TestController::class, 'show']);
//     Route::post('/create',       [\App\Http\Controllers\Test\TestController::class, 'store']);
//     Route::post('/update',       [\App\Http\Controllers\Test\TestController::class, 'update']);
//     Route::post('/activate',     [\App\Http\Controllers\Test\TestController::class, 'activate']);
//     Route::post('/deactivate',   [\App\Http\Controllers\Test\TestController::class, 'deactivate']);
// });

// Category PUBLIC ROUTES — DISABLED, see note above.
// Route::prefix('category')->group(function () {
//     Route::get('/all/paginated', [\App\Http\Controllers\Category\CategoryController::class, 'allPaginated']);
//     Route::get('/all',           [\App\Http\Controllers\Category\CategoryController::class, 'all']);
//     Route::post('/show',         [\App\Http\Controllers\Category\CategoryController::class, 'show']);
//     Route::post('/create',       [\App\Http\Controllers\Category\CategoryController::class, 'store']);
//     Route::post('/update',       [\App\Http\Controllers\Category\CategoryController::class, 'update']);
//     Route::post('/activate',     [\App\Http\Controllers\Category\CategoryController::class, 'activate']);
//     Route::post('/deactivate',   [\App\Http\Controllers\Category\CategoryController::class, 'deactivate']);
// });
