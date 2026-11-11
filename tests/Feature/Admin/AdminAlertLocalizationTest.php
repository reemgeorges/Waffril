<?php

namespace Tests\Feature\Admin;

use App\Filament\Support\LocalizedNotification;
use App\Models\Admin;
use App\Services\Notifications\AdminAlerts;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * WAFFRLI (2026-11-11) — bell alerts follow the viewer's CURRENT panel
 * language, including alerts stored before this change (English or Arabic
 * text only); unknown text is never guessed; read state is untouched.
 */
class AdminAlertLocalizationTest extends AdminPanelTestCase
{
    /** An alert exactly as AdminAlerts stored it before 2026-11-11. */
    protected function legacyAlert(Admin $admin, string $type, string $title, string $body, string $open = 'Open'): DatabaseNotification
    {
        return $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => FilamentDatabaseNotification::class,
            'data' => [
                'actions' => [['name' => 'open', 'label' => $open, 'url' => 'http://localhost/admin/orders/1', 'shouldMarkAsRead' => true]],
                'body' => $body,
                'color' => null,
                'duration' => 'persistent',
                'icon' => 'heroicon-o-shopping-bag',
                'iconColor' => 'info',
                'status' => null,
                'title' => $title,
                'view' => 'filament-notifications::notification',
                'viewData' => ['waffrli_alert' => $type],
                'format' => 'filament',
            ],
        ]);
    }

    public function test_the_bell_uses_the_localized_notification(): void
    {
        $this->assertInstanceOf(LocalizedNotification::class, Notification::make());
    }

    public function test_existing_english_alerts_are_shown_in_arabic_and_back_in_english(): void
    {
        $admin = $this->superAdmin();
        $alert = $this->legacyAlert($admin, 'order.placed', 'New order', 'Order WF261110-8RSIEC was placed.');
        $this->actingAsAdmin($admin);

        app()->setLocale('ar');
        Livewire::test(DatabaseNotifications::class)
            ->assertSee(__('admin.alerts.order_placed.title', [], 'ar'))
            ->assertSee(__('admin.alerts.open', [], 'ar'))
            ->assertSee('WF261110-8RSIEC')
            ->assertDontSee('New order')
            ->assertDontSee('was placed');

        app()->setLocale('en');
        Livewire::test(DatabaseNotifications::class)
            ->assertSee('New order')->assertSee('Order')->assertSee('was placed')->assertSee('Open')
            ->assertDontSee(__('admin.alerts.order_placed.title', [], 'ar'));

        $this->assertNull($alert->fresh()->read_at, 'Rendering never changes the read state.');
        $this->assertSame('Order WF261110-8RSIEC was placed.', $alert->fresh()->data['body'], 'Stored data is not rewritten.');
    }

    public function test_every_alert_type_round_trips_from_either_stored_language(): void
    {
        $admin = $this->superAdmin();
        foreach (array_keys(AdminAlerts::ALERTS) as $type) {
            $key = AdminAlerts::translationKey($type);
            $reference = $type === 'plus.request_submitted' ? '#42' : 'WF261111-ABC123';
            foreach (['en', 'ar'] as $stored) {
                $alert = $this->legacyAlert($admin, $type, __($key.'.title', [], $stored), __($key.'.body', ['reference' => $reference], $stored), __('admin.alerts.open', [], $stored));
                foreach (['ar', 'en'] as $viewer) {
                    app()->setLocale($viewer);
                    $shown = Notification::fromDatabase($alert);
                    $this->assertSame(__($key.'.title', [], $viewer), $shown->getTitle(), "{$type} stored in {$stored}, viewed in {$viewer}");
                    $this->assertSame(
                        __($key.'.body', ['reference' => LocalizedNotification::isolate($reference)], $viewer),
                        $shown->getBody(),
                        "{$type} stored in {$stored}, viewed in {$viewer}",
                    );
                    $this->assertSame(__('admin.alerts.open', [], $viewer), $shown->getActions()[0]->getLabel());
                    $this->assertSame('/admin/orders/1', $shown->getActions()[0]->getUrl(), 'The link works on any host serving the panel.');
                }
            }
        }
    }

    public function test_unknown_text_is_kept_and_other_notifications_are_untouched(): void
    {
        $admin = $this->superAdmin();
        app()->setLocale('ar');

        // A known type whose sentence matches no template: title translated, body kept.
        $odd = $this->legacyAlert($admin, 'order.placed', 'New order', 'Something an operator wrote by hand.');
        $shown = Notification::fromDatabase($odd);
        $this->assertSame(__('admin.alerts.order_placed.title', [], 'ar'), $shown->getTitle());
        $this->assertSame('Something an operator wrote by hand.', $shown->getBody());

        // Not a WAFFRLI alert at all: nothing changes.
        $plain = $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => FilamentDatabaseNotification::class,
            'data' => ['title' => 'Export finished', 'body' => 'Your file is ready.', 'actions' => [['name' => 'open', 'label' => 'Open', 'url' => 'http://localhost']], 'viewData' => [], 'format' => 'filament'],
        ]);
        $shown = Notification::fromDatabase($plain);
        $this->assertSame('Export finished', $shown->getTitle());
        $this->assertSame('Your file is ready.', $shown->getBody());
        $this->assertSame('Open', $shown->getActions()[0]->getLabel());
    }

    public function test_new_alerts_are_identical_for_every_admin_whatever_their_language(): void
    {
        $arabic = Admin::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $english = Admin::factory()->create(['is_active' => true, 'locale' => 'en']);
        foreach ([$arabic, $english] as $admin) {
            $admin->givePermissionTo('orders.view');
        }
        app()->setLocale('ar'); // the event happens while someone works in Arabic

        AdminAlerts::send('order.placed', 1, 'WF261111-NEW001');

        $data = fn (Admin $admin) => $admin->notifications()->latest()->first()->data;
        $this->assertSame($data($arabic)['title'], $data($english)['title']);
        $this->assertSame($data($arabic)['body'], $data($english)['body']);
        $this->assertSame(['waffrli_alert' => 'order.placed', 'waffrli_params' => ['reference' => 'WF261111-NEW001']], $data($arabic)['viewData']);

        foreach (['ar', 'en'] as $viewer) {
            app()->setLocale($viewer);
            $shown = Notification::fromDatabase($english->notifications()->latest()->first());
            $this->assertSame(__('admin.alerts.order_placed.title', [], $viewer), $shown->getTitle());
            $this->assertStringContainsString('WF261111-NEW001', (string) $shown->getBody());
            $this->assertSame(__('admin.alerts.open', [], $viewer), $shown->getActions()[0]->getLabel());
        }
    }

    public function test_relative_times_follow_the_panel_language(): void
    {
        $admin = $this->superAdmin();
        $alert = $this->legacyAlert($admin, 'order.placed', 'New order', 'Order WF261110-8RSIEC was placed.');
        $alert->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->actingAsAdmin($admin);

        app()->setLocale('ar');
        Livewire::test(DatabaseNotifications::class)->assertSee(now()->subHours(3)->locale('ar')->diffForHumans());
        app()->setLocale('en');
        Livewire::test(DatabaseNotifications::class)->assertSee('3 hours ago');
    }
}
