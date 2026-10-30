<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\SetAdminPanelLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * WAFFRLI (2026-10-31) — the admin panel at /admin.
 *
 * Authenticates against the `admins` table through the dedicated
 * `admin_web` session guard (never `users`); Admin::canAccessPanel()
 * admits only active, non-deleted admins. Navigation and every resource
 * are permission-aware through the model policies (App\Policies).
 * There is no merchant/store-owner area.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('admin_web')
            ->brandName('WAFFRLI')
            ->colors(['primary' => Color::Orange])
            ->navigationGroups([
                NavigationGroup::make('operations')->label(fn () => __('admin.nav.operations')),
                NavigationGroup::make('customers')->label(fn () => __('admin.nav.customers')),
                NavigationGroup::make('drivers')->label(fn () => __('admin.nav.drivers')),
                NavigationGroup::make('catalog')->label(fn () => __('admin.nav.catalog')),
                NavigationGroup::make('finance')->label(fn () => __('admin.nav.finance')),
                NavigationGroup::make('plus')->label(fn () => __('admin.nav.plus')),
                NavigationGroup::make('platform')->label(fn () => __('admin.nav.platform')),
                NavigationGroup::make('system')->label(fn () => __('admin.nav.system')),
            ])
            ->userMenuItems([
                Action::make('locale_ar')->label('العربية')->icon('heroicon-o-language')->url(fn () => route('admin.locale', 'ar')),
                Action::make('locale_en')->label('English')->icon('heroicon-o-language')->url(fn () => route('admin.locale', 'en')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetAdminPanelLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
