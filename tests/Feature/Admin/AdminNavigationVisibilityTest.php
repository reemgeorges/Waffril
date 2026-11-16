<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\CurrencyRedenominations\CurrencyRedenominationResource;
use App\Filament\Resources\ErrorEvents\ErrorEventResource;
use App\Models\ErrorEvent;

/**
 * WAFFRLI (2026-11-16) — technical screens stay out of the everyday sidebar:
 *  - Monitoring → Errors is a developer diagnostic: never in the sidebar,
 *    still reachable directly with errors.view, refused to everyone else;
 *  - Currency redenominations only appear while the currency-switch feature
 *    flag is on; the Currencies page is always there.
 */
class AdminNavigationVisibilityTest extends AdminPanelTestCase
{
    private function sidebar(): string
    {
        return (string) $this->get('/admin')->assertOk()->getContent();
    }

    public function test_errors_are_not_in_the_sidebar_but_stay_authorized_by_url(): void
    {
        ErrorEvent::create(['fingerprint' => str_repeat('b', 64), 'exception_class' => 'RuntimeException', 'message' => 'x', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(), 'environment' => 'testing']);
        $this->actingAsAdmin($this->superAdmin());

        $this->assertFalse(ErrorEventResource::shouldRegisterNavigation());
        $this->assertStringNotContainsString(ErrorEventResource::getUrl('index'), $this->sidebar());
        $this->get(ErrorEventResource::getUrl('index'))->assertOk();

        $this->actingAsAdmin($this->employee(['errors.view']));
        $this->get(ErrorEventResource::getUrl('index'))->assertOk();

        $this->actingAsAdmin($this->employee(['orders.view', 'audit.view']));
        $this->get(ErrorEventResource::getUrl('index'))->assertForbidden();
    }

    public function test_redenomination_entry_follows_the_feature_flag_and_currencies_stay(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        config(['waffrli.currency.redenomination_enabled' => false]);
        $this->assertFalse(CurrencyRedenominationResource::shouldRegisterNavigation());
        $html = $this->sidebar();
        $this->assertStringNotContainsString(CurrencyRedenominationResource::getUrl('index'), $html);
        $this->assertStringContainsString(CurrencyResource::getUrl('index'), $html);
        $this->get(CurrencyResource::getUrl('index'))->assertOk();

        // With the flag on it is registered again (shown under Currencies) and
        // explains that it changes the accounting base, not the SYP display.
        config(['waffrli.currency.redenomination_enabled' => true]);
        $this->assertTrue(CurrencyRedenominationResource::shouldRegisterNavigation());
        $this->get(CurrencyRedenominationResource::getUrl('index'))->assertOk()
            ->assertSee(__('admin.redenomination.base_not_denomination'));
    }
}
