<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Stores\StoreResource;
use App\Support\Settings\PlatformSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * WAFFRLI (2026-10-31) — operational dashboard (widgets in
 * app/Filament/Widgets, each permission-aware).
 *
 * Layout (UX redesign 2026-11-11), by operational importance:
 *  1. Needs your attention: every queue an employee must act on, linked.
 *  2. Today: received, delivered, cancelled, average delivery time, value.
 *  3. 14-day trend (two thirds) beside the live order pipeline (one third).
 *  4. Orders waiting for acceptance.
 *  5. Finance per currency.  6. Platform size.
 * Header shortcuts open the most common tasks; each is shown only when the
 * admin may open its page.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }

    /** A greeting with today's date in the platform timezone and panel language. */
    public function getSubheading(): ?string
    {
        $locale = app()->getLocale();
        $now = now(PlatformSettings::timezone())->locale($locale);
        $greeting = $now->hour < 12 ? __('admin.dashboard.good_morning') : __('admin.dashboard.good_evening');
        $name = auth()->user()?->name;

        return trim($greeting.($name ? ($locale === 'ar' ? '، ' : ', ').$name : '').' · '.$now->isoFormat($locale === 'ar' ? 'dddd، D MMMM YYYY' : 'dddd, D MMMM YYYY'));
    }

    /** @return int|array<string, int> */
    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pendingOrders')->label(__('admin.dashboard.action_pending_orders'))
                ->icon('heroicon-m-inbox-arrow-down')
                ->url(fn () => OrderResource::getUrl('index', ['activeTab' => 'pending']))
                ->visible(fn () => OrderResource::canViewAny()),
            Action::make('driverMap')->label(__('admin.drivers.map'))->color('gray')
                ->icon('heroicon-m-map')
                ->url(fn () => DriverMapPage::getUrl())
                ->visible(fn () => DriverMapPage::canAccess()),
            ActionGroup::make([
                Action::make('newStore')->label(__('admin.dashboard.action_new_store'))->icon('heroicon-m-building-storefront')
                    ->url(fn () => StoreResource::getUrl('create'))->visible(fn () => StoreResource::canCreate()),
                Action::make('newProduct')->label(__('admin.dashboard.action_new_product'))->icon('heroicon-m-cube')
                    ->url(fn () => ProductResource::getUrl('create'))->visible(fn () => ProductResource::canCreate()),
            ])->label(__('admin.dashboard.action_add'))->icon('heroicon-m-plus')->button()->color('gray'),
        ];
    }
}
