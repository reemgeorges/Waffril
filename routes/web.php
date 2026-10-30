<?php

use App\Http\Middleware\SetAdminPanelLocale;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// WAFFRLI (2026-10-31): admin panel language switch (session only; ar enables RTL).
Route::get('/admin/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, SetAdminPanelLocale::SUPPORTED, true), 404);
    session(['admin_locale' => $locale]);

    return redirect()->back(fallback: url('/admin'));
})->name('admin.locale');

// WAFFRLI (2026-11-03): Reverb private-channel authorization for the Filament
// panel (admin_web session only). Channels: routes/channels.php.
Route::post('/broadcasting/auth', \App\Http\Controllers\Realtime\BroadcastAuthController::class)
    ->middleware(['auth:admin_web', 'throttle:60,1'])
    ->name('broadcasting.auth');
