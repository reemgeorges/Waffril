<?php

use App\Console\Commands\Waffrli\BuildOpenApiCommand;
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

// WAFFRLI (2026-11-05): interactive API reference (Scalar, vendored — no CDN)
// over the generated OpenAPI document. Opt-in (WAFFRLI_API_DOCS_ENABLED=true)
// and never registered in production. Read-only; "try it" calls hit this
// same host with the caller's own token.
if (config('waffrli.api_docs.enabled') && ! app()->environment('production')) {
    Route::get('/docs', fn () => response()->view('api-docs', [
        'scalarAvailable' => is_file(public_path('vendor/scalar/standalone.js')),
    ]))->middleware('throttle:60,1')->name('api-docs');

    Route::get('/docs/openapi.yaml', fn () => response()->file(base_path(BuildOpenApiCommand::PATH), [
        'Content-Type' => 'application/yaml; charset=utf-8',
    ]))->middleware('throttle:60,1')->name('api-docs.spec');
}
