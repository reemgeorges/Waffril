<?php

use App\Http\Controllers\Realtime\BroadcastAuthController;
use App\Http\Middleware\SetAdminPanelLocale;
use App\Models\Admin;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// WAFFRLI (2026-10-31, 2026-11-04): admin panel language switch (ar enables
// RTL). POST + CSRF; saved on the signed-in admin (admins.locale), in the
// session and in a one-year cookie for the login page.
Route::post('/admin/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, SetAdminPanelLocale::SUPPORTED, true), 404);
    session(['admin_locale' => $locale]);
    $admin = auth('admin_web')->user();
    if ($admin instanceof Admin) {
        $admin->forceFill(['locale' => $locale])->save();
    }

    return redirect()->back(fallback: url('/admin'))
        ->withCookie(cookie()->forever(SetAdminPanelLocale::COOKIE, $locale));
})->middleware('throttle:30,1')->name('admin.locale');

// WAFFRLI (2026-11-03): Reverb private-channel authorization for the Filament
// panel (admin_web session only). Channels: routes/channels.php.
Route::post('/broadcasting/auth', BroadcastAuthController::class)
    ->middleware(['auth:admin_web', 'throttle:60,1'])
    ->name('broadcasting.auth');
