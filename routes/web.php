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
