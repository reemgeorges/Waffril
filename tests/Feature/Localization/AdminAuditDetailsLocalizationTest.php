<?php

namespace Tests\Feature\Localization;

use App\Filament\Support\Readable;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-16) — the audit log "details" column showed keys that are
 * not form fields as English headlines ("Payment Id", "Expected",
 * "Generated Demo") in the Arabic panel. Every key written by
 * AdminAudit::log() must have a label in both languages.
 */
class AdminAuditDetailsLocalizationTest extends TestCase
{
    /** @return list<string> */
    protected function loggedKeys(): array
    {
        $keys = ['generated_demo']; // AdminAudit::withContext() in the demo seeders
        foreach (Finder::create()->in([app_path(), database_path('seeders')])->name('*.php') as $file) {
            if (preg_match_all('/AdminAudit::log\((.*?)\);/s', $file->getContents(), $calls)) {
                foreach ($calls[1] as $call) {
                    preg_match_all("/'([a-z_]+)'\\s*=>/", $call, $found);
                    $keys = array_merge($keys, $found[1]);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_every_logged_audit_key_has_an_arabic_and_english_label(): void
    {
        $keys = $this->loggedKeys();
        $this->assertGreaterThan(20, count($keys), 'The scan must find the audit keys.');

        foreach ($keys as $key) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(
                    Lang::has("admin.fields.{$key}", $locale) || Lang::has("admin.audit_keys.{$key}", $locale),
                    "Audit key [{$key}] has no {$locale} label (admin.fields or admin.audit_keys)."
                );
            }
        }
    }

    public function test_audit_details_render_in_arabic(): void
    {
        app()->setLocale('ar');
        $text = Readable::properties(['payment_id' => 23, 'expected' => '2.76', 'generated_demo' => true, 'policy' => 'monthly', 'reason' => 'customer_request']);

        $this->assertStringNotContainsString('Payment Id', $text);
        $this->assertStringNotContainsString('Generated Demo', $text);
        $this->assertStringContainsString(__('admin.audit_keys.payment_id', [], 'ar'), $text);
        $this->assertStringContainsString(__('admin.plus.policy_monthly', [], 'ar'), $text, 'Coded values are translated too.');
        $this->assertStringContainsString(__('admin.orders.reason_customer_request', [], 'ar'), $text);

        app()->setLocale('en');
        $this->assertStringContainsString('Payment: 23', Readable::properties(['payment_id' => 23]));
    }
}
