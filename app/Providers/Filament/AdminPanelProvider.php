<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\Brand;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\LocalizedNotification;
use App\Filament\Support\TableDefaults;
use App\Http\Middleware\SetAdminPanelLocale;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
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
    public function register(): void
    {
        parent::register();

        // Admin alerts in the bell are rendered in the viewer's current panel
        // language (Filament rebuilds notifications through this binding).
        $this->app->bind(Notification::class, LocalizedNotification::class);
    }

    /** Shared table layout policy (alignment, compact widths, empty values). */
    public function boot(): void
    {
        TableDefaults::register();
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('admin_web')
            // WAFFRLI (2026-11-04, redesigned 2026-11-11): official brand logo
            // (public/images/brand) on a neutral graphite panel with restrained
            // brand green (App\Filament\Support\Brand), compiled custom theme
            // (resources/css/filament/admin/theme.css → public/css/filament/admin/theme.css),
            // self-hosted IBM Plex Sans Arabic, dark mode by default.
            ->brandName(fn () => __('admin.brand.name'))
            ->brandLogo(fn () => view('filament.brand.logo'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/brand/favicon-48.png'))
            ->colors(Brand::colors())
            ->font('IBM Plex Sans Arabic', url: asset('fonts/ibm-plex-sans-arabic/font.css'), provider: LocalFontProvider::class)
            ->theme(asset('css/filament/admin/theme.css'))
            ->defaultThemeMode(ThemeMode::Dark)
            // Layout: wider working area for tables and the dashboard (1536px
            // instead of 1280px), a slimmer sidebar that can collapse to icons.
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->sidebarWidth('17rem')
            ->sidebarCollapsibleOnDesktop()
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.brand.head'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.brand.locale-switcher'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('filament.brand.locale-switcher', ['floating' => true]))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn () => view('filament.brand.login-hero'))
            // WAFFRLI (2026-11-03): operational alerts in the bell. Live over the
            // shared Reverb server when configured (config/filament.php echo,
            // channel admin.{id}); the 30s poll is the fallback.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Keyed by the group id each resource/page declares, so Filament
            // matches items to these (translated, ordered) groups.
            // UX redesign (2026-11-11): groups follow the daily work, most used
            // first. Configuration groups start collapsed so they stay reachable
            // without crowding the menu. Visibility stays permission-aware: a
            // group appears only when its member can see at least one item.
            ->navigationGroups([
                'operations' => NavigationGroup::make()->label(fn () => __('admin.nav.operations')),
                'catalog' => NavigationGroup::make()->label(fn () => __('admin.nav.catalog')),
                'people' => NavigationGroup::make()->label(fn () => __('admin.nav.people')),
                'finance' => NavigationGroup::make()->label(fn () => __('admin.nav.finance')),
                'plus' => NavigationGroup::make()->label(fn () => __('admin.nav.plus')),
                'delivery' => NavigationGroup::make()->label(fn () => __('admin.nav.delivery'))->collapsed(),
                'platform' => NavigationGroup::make()->label(fn () => __('admin.nav.platform'))->collapsed(),
                'system' => NavigationGroup::make()->label(fn () => __('admin.nav.system'))->collapsed(),
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
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.realtime.operations-listener'))
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
