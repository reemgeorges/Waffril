<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Stores\StoreResource;
use App\Models\Order;
use App\Support\Settings\PlatformSettings;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\View\View;

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

    /**
     * UX redesign round 2 (2026-11-11): a branded hero replaces the plain page
     * header: greeting and date, live status chips and the common shortcuts.
     * Each shortcut and chip appears only when the admin may open its page.
     */
    public function getHeader(): ?View
    {
        $locale = app()->getLocale();
        $now = now(PlatformSettings::timezone())->locale($locale);
        $canOrders = OrderResource::canViewAny();

        $shortcuts = array_values(array_filter([
            $canOrders ? ['label' => __('admin.dashboard.action_pending_orders'), 'icon' => 'heroicon-m-inbox-arrow-down', 'url' => OrderResource::getUrl('index', ['tab' => 'pending']), 'primary' => true] : null,
            DriverMapPage::canAccess() ? ['label' => __('admin.drivers.map'), 'icon' => 'heroicon-m-map', 'url' => DriverMapPage::getUrl()] : null,
            StoreResource::canCreate() ? ['label' => __('admin.dashboard.action_new_store'), 'icon' => 'heroicon-m-building-storefront', 'url' => StoreResource::getUrl('create')] : null,
            // Products are added inside their store (Stores → store → Products).
            StoreResource::canViewAny() ? ['label' => __('admin.resources.store.plural'), 'icon' => 'heroicon-m-squares-plus', 'url' => StoreResource::getUrl('index')] : null,
        ]));

        return view('filament.dashboard.hero', [
            'title' => $this->getTitle(),
            'greeting' => ($now->hour < 12 ? __('admin.dashboard.good_morning') : __('admin.dashboard.good_evening'))
                .(auth()->user()?->name ? ($locale === 'ar' ? '، ' : ', ').auth()->user()->name : ''),
            'date' => $now->isoFormat($locale === 'ar' ? 'dddd، D MMMM YYYY' : 'dddd, D MMMM YYYY'),
            'chips' => $canOrders ? [
                ['tone' => 'transit', 'label' => __('admin.dashboard.active_deliveries'), 'count' => Order::query()->whereIn('status', OrderStatus::active())->count(), 'url' => OrderResource::getUrl('index', ['tab' => 'on_the_road'])],
                ['tone' => 'warning', 'label' => __('admin.dashboard.pending_orders'), 'count' => Order::query()->where('status', OrderStatus::Pending->value)->count(), 'url' => OrderResource::getUrl('index', ['tab' => 'pending'])],
            ] : [],
            'shortcuts' => $shortcuts,
        ]);
    }
}
