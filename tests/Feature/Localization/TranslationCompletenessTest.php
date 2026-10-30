<?php

namespace Tests\Feature\Localization;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\MarketingBroadcast;
use App\Models\OrderPayment;
use App\Models\PlusRequest;
use App\Models\WalletTransaction;
use App\Notifications\DriverDeliveryProblemReported;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\Lang;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-04) — Arabic and English must stay complete and aligned:
 *  - every lang file exists in both locales with the same keys;
 *  - placeholders (:name) match key by key;
 *  - every literal key used in application code resolves in BOTH locales
 *    (no raw "messages.x" ever reaches a user);
 *  - every dynamic key family (statuses, permissions, settings…) resolves
 *    for each value the code can produce.
 */
class TranslationCompletenessTest extends TestCase
{
    public const LOCALES = ['ar', 'en'];

    protected function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            is_array($value) ? $flat += $this->flatten($value, $path) : $flat[$path] = (string) $value;
        }

        return $flat;
    }

    protected function files(string $locale): array
    {
        return collect(glob(lang_path("{$locale}/*.php")))->map(fn ($f) => basename($f))->sort()->values()->all();
    }

    public function test_both_locales_have_the_same_files_keys_and_placeholders(): void
    {
        $this->assertSame($this->files('en'), $this->files('ar'), 'Same translation files in ar and en.');

        foreach ($this->files('en') as $file) {
            $en = $this->flatten(require lang_path("en/{$file}"));
            $ar = $this->flatten(require lang_path("ar/{$file}"));
            $this->assertSame([], array_keys(array_diff_key($en, $ar)), "{$file}: keys missing in Arabic.");
            $this->assertSame([], array_keys(array_diff_key($ar, $en)), "{$file}: keys missing in English.");

            foreach ($en as $key => $text) {
                preg_match_all('/:([a-z_]+)/', $text, $a);
                preg_match_all('/:([a-z_]+)/', $ar[$key], $b);
                $x = array_values(array_unique($a[1]));
                $y = array_values(array_unique($b[1]));
                sort($x);
                sort($y);
                $this->assertSame($x, $y, "{$file}: placeholders differ for {$key}.");
                $this->assertNotSame('', trim($ar[$key]), "{$file}: empty Arabic text for {$key}.");
            }
        }
    }

    public function test_every_literal_key_used_in_code_exists_in_both_locales(): void
    {
        $finder = (new Finder)->files()->name('*.php')->in([app_path(), base_path('routes'), resource_path('views'), database_path('seeders')]);
        $missing = [];
        foreach ($finder as $file) {
            preg_match_all("/(?:__|trans|trans_choice|Lang::get|@lang)\(\s*['\"]([a-z_]+\.[A-Za-z0-9_.\-]+)['\"]\s*[,)]/", $file->getContents(), $m);
            foreach (array_unique($m[1]) as $key) {
                foreach (self::LOCALES as $locale) {
                    if (! Lang::has($key, $locale, false)) {
                        $missing[] = "{$locale}: {$key} ({$file->getRelativePathname()})";
                    }
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_dynamic_key_families_resolve_for_every_value(): void
    {
        $constants = fn (string $class, string $prefix) => array_values(array_filter(
            (new ReflectionClass($class))->getConstants(),
            fn ($v, $k) => str_starts_with($k, $prefix) && is_string($v),
            ARRAY_FILTER_USE_BOTH,
        ));

        $keys = [];
        foreach (OrderStatus::cases() as $status) {
            $keys[] = 'admin.order_status.'.$status->value;
        }
        foreach ($constants(OrderPayment::class, 'STATUS_') as $v) {
            $keys[] = "admin.payments.status_{$v}";
        }
        foreach ($constants(WalletTransaction::class, 'TYPE_') as $v) {
            $keys[] = "admin.wallets.type_{$v}";
        }
        foreach (['credit', 'debit'] as $v) {
            $keys[] = "admin.wallets.direction_{$v}";
        }
        foreach ($constants(Driver::class, 'AVAILABILITY_') as $v) {
            $keys[] = "admin.drivers.availability_{$v}";
        }
        foreach ($constants(PlusRequest::class, 'TYPE_') as $v) {
            $keys[] = "admin.plus.request_{$v}";
        }
        foreach ($constants(PlusRequest::class, 'STATUS_') as $v) {
            $keys[] = "admin.requests.status_{$v}";
        }
        foreach (array_merge($constants(MarketingBroadcast::class, 'STATUS_'), $constants(MarketingBroadcast::class, 'AUDIENCE_')) as $v) {
            $keys[] = "admin.marketing.{$v}";
        }
        foreach (DriverDeliveryProblemReported::TYPES as $type) {
            $keys[] = "messages.driver.problem_types.{$type}";
        }
        foreach (AdminPermissions::catalogue() as $group => $permissions) {
            $keys[] = "admin.permission_groups.{$group}";
            foreach ($permissions as $permission) {
                $keys[] = "admin.permissions.{$permission}";
            }
        }
        foreach (array_keys(PlatformSettings::definitions()) as $setting) {
            $keys[] = "admin.settings.keys.{$setting}";
        }

        $missing = [];
        foreach ($keys as $key) {
            foreach (self::LOCALES as $locale) {
                if (! Lang::has($key, $locale, false)) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_api_validation_errors_are_localized(): void
    {
        foreach (['ar' => 'رقم الهاتف', 'en' => 'phone number'] as $locale => $attribute) {
            $error = $this->withHeader('Accept-Language', $locale)
                ->postJson('/api/v1/customer/auth/otp/request', [])
                ->assertStatus(400)->json('error');
            $this->assertStringContainsString($attribute, $error, "{$locale} validation message");
        }
    }
}
