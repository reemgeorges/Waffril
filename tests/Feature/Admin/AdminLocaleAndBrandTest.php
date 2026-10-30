<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Http\Middleware\SetAdminPanelLocale;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

/**
 * WAFFRLI (2026-11-04) — admin panel language (per-admin, persisted, RTL/LTR)
 * and the official branding.
 */
class AdminLocaleAndBrandTest extends AdminPanelTestCase
{
    public function test_each_admin_keeps_their_own_language_across_requests_and_logins(): void
    {
        $arabic = $this->superAdmin();
        $english = $this->superAdmin();

        $this->actingAsAdmin($arabic)->post('/admin/locale/ar')->assertRedirect()->assertCookie(SetAdminPanelLocale::COOKIE, 'ar');
        $this->assertSame('ar', $arabic->fresh()->locale);
        $this->get('/admin')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);

        // Another admin is unaffected (no shared session state).
        $this->flushSession();
        $this->actingAsAdmin($english)->get('/admin')->assertOk()->assertSee('dir="ltr"', false);
        $this->assertNull($english->fresh()->locale);

        // A new session (logout → login) applies the saved preference.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin($arabic->fresh())->get('/admin')->assertOk()->assertSee('dir="rtl"', false);

        $this->post('/admin/locale/en')->assertRedirect();
        $this->get('/admin')->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
        $this->post('/admin/locale/fr')->assertNotFound();
    }

    public function test_livewire_actions_and_validation_follow_the_admin_language(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAsAdmin($admin);
        app()->setLocale('ar'); // what SetAdminPanelLocale does for Livewire requests (persistent middleware)

        $component = Livewire::test(ManageStoreCategories::class)
            ->callAction(TestAction::make('create'), ['name' => ['ar' => '', 'en' => 'Grocery'], 'slug' => 'grocery'])
            ->assertHasFormErrors(['name.ar' => 'required']);
        $message = collect($component->errors()->getMessages())->flatten()->first();
        $this->assertStringContainsString('مطلوب', (string) $message, 'Arabic validation message in the panel.');
    }

    public function test_login_page_is_branded_and_offers_the_language_switch(): void
    {
        $page = $this->get('/admin/login')->assertOk();
        $page->assertSee('images/brand/waffrli-logo-256.webp', false)
            ->assertSee('data-testid="locale-switcher"', false)
            ->assertSee('css/filament/admin/theme.css', false)
            ->assertSee('fonts/ibm-plex-sans-arabic/font.css', false)
            ->assertSee('images/brand/favicon-32.png', false)
            ->assertDontSee('fonts.bunny.net', false);

        $this->withCookie(SetAdminPanelLocale::COOKIE, 'ar')->get('/admin/login')->assertSee('dir="rtl"', false)->assertSee(__('admin.brand.tagline', [], 'ar'));
        $this->assertFileExists(public_path('css/filament/admin/theme.css'));
    }

    public function test_panel_shows_the_brand_lockup_and_switcher_without_secrets(): void
    {
        config(['broadcasting.connections.reverb.secret' => 'server-only-secret']);
        $html = $this->actingAsAdmin($this->superAdmin())->get('/admin')->assertOk()
            ->assertSee('wf-brand', false)->assertSee('وفرلي')->assertSee('data-locale="ar"', false)
            ->getContent();
        $this->assertStringNotContainsString('server-only-secret', $html);
    }

    public function test_a_language_chosen_on_the_login_page_is_saved_when_signing_in(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['password' => 'login-test-password-1', 'locale' => 'en'])->save();

        $this->post('/admin/locale/ar')->assertRedirect();
        $this->assertSame('ar', session('admin_locale'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'login-test-password-1'])
            ->call('authenticate')->assertHasNoFormErrors();

        $this->assertSame('ar', $admin->fresh()->locale, 'Explicit choice on the login page wins and is saved.');
    }
}
