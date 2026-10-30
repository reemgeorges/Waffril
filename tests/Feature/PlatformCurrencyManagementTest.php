<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Services\Functional\Currency\CurrencyConverter;
use App\Support\Auth\PlatformPermissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlatformTestHelpers;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — currency management rules: first default
 * initialization only, no switching of an existing default, default rate
 * fixed at 1, default never deactivated, single default enforced by the
 * database, exact cross-rate conversion.
 */
class PlatformCurrencyManagementTest extends TestCase
{
    use PlatformTestHelpers, RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->adminToken([PlatformPermissions::CURRENCIES_MANAGE]);
    }

    protected function createCurrency(array $overrides = [])
    {
        return $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/create', array_merge([
            'code' => 'usd',
            'name' => ['ar' => 'دولار أمريكي', 'en' => 'US Dollar'],
            'symbol' => '$',
            'decimals' => 2,
            'exchange_rate' => '1',
        ], $overrides));
    }

    protected function bootstrapDefaultUsd(): Currency
    {
        $this->createCurrency()->assertOk();
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/initialize-default', ['id' => $usd->id])->assertOk();

        return $usd->fresh();
    }

    public function test_first_currency_is_created_with_both_translations_and_normalized_code(): void
    {
        $response = $this->createCurrency()->assertOk();

        $response->assertJsonPath('status', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('data.currency.code', 'USD')
            ->assertJsonPath('data.currency.is_default', false)
            ->assertJsonPath('data.currency.name_translations.ar', 'دولار أمريكي');
    }

    public function test_a_second_currency_requires_an_initialized_default(): void
    {
        $this->createCurrency()->assertOk();

        $this->createCurrency(['code' => 'SYP', 'exchange_rate' => '13000'])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertSame(1, Currency::count());
    }

    public function test_initialize_default_sets_rate_one_and_cannot_run_twice(): void
    {
        $this->createCurrency(['exchange_rate' => '3.5'])->assertOk();
        $usd = Currency::firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/initialize-default', ['id' => $usd->id])
            ->assertOk()
            ->assertJsonPath('data.currency.is_default', true);

        $usd->refresh();
        $this->assertTrue($usd->is_default);
        $this->assertSame('1.000000000000', (string) $usd->exchange_rate);

        $this->createCurrency(['code' => 'SYP', 'exchange_rate' => '13000'])->assertOk();
        $syp = Currency::where('code', 'SYP')->firstOrFail();

        // Switching an existing default is prohibited in P0.
        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/initialize-default', ['id' => $syp->id])
            ->assertStatus(409);
        $this->assertTrue($usd->fresh()->is_default);
        $this->assertFalse($syp->fresh()->is_default);
    }

    public function test_is_default_can_never_be_sent_on_create_or_update(): void
    {
        $this->createCurrency(['is_default' => true])->assertStatus(400);

        $usd = $this->bootstrapDefaultUsd();
        $this->createCurrency(['code' => 'SYP', 'exchange_rate' => '13000'])->assertOk();
        $syp = Currency::where('code', 'SYP')->firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/update', ['id' => $syp->id, 'is_default' => true])
            ->assertStatus(400);
        $this->assertTrue($usd->fresh()->is_default);
    }

    public function test_default_rate_is_fixed_and_default_cannot_be_deactivated(): void
    {
        $usd = $this->bootstrapDefaultUsd();

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/update', ['id' => $usd->id, 'exchange_rate' => '2'])
            ->assertStatus(422);

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/deactivate', ['id' => $usd->id])
            ->assertStatus(422);

        $this->assertTrue($usd->fresh()->is_active);
        $this->assertSame('1.000000000000', (string) $usd->fresh()->exchange_rate);
    }

    public function test_code_is_immutable_and_non_default_rate_updates_are_tracked(): void
    {
        $this->bootstrapDefaultUsd();
        $this->createCurrency(['code' => 'SYP', 'exchange_rate' => '13000'])->assertOk();
        $syp = Currency::where('code', 'SYP')->firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/update', ['id' => $syp->id, 'code' => 'EUR'])
            ->assertStatus(400);

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/update', ['id' => $syp->id, 'exchange_rate' => '13250.5'])
            ->assertOk();

        $syp->refresh();
        $this->assertSame('SYP', $syp->code);
        $this->assertSame('13250.500000000000', (string) $syp->exchange_rate);
        $this->assertNotNull($syp->rate_updated_by_admin_id);

        $this->asBearer($this->token)->postJson('/api/v1/admin/currencies/deactivate', ['id' => $syp->id])->assertOk();
        $this->assertFalse($syp->fresh()->is_active);
    }

    public function test_rate_validation_rejects_non_positive_and_over_precise_rates(): void
    {
        $this->createCurrency(['exchange_rate' => '0'])->assertStatus(400);
        $this->createCurrency(['exchange_rate' => '-1'])->assertStatus(400);
        $this->createCurrency(['exchange_rate' => '1.0000000000001'])->assertStatus(400);
        $this->createCurrency(['decimals' => 5])->assertStatus(400);
        $this->assertSame(0, Currency::count());
    }

    public function test_database_allows_only_one_default_currency(): void
    {
        $this->bootstrapDefaultUsd();
        $this->createCurrency(['code' => 'SYP', 'exchange_rate' => '13000'])->assertOk();

        $this->expectException(QueryException::class);
        Currency::where('code', 'SYP')->firstOrFail()->forceFill(['is_default' => true, 'exchange_rate' => '1'])->save();
    }

    public function test_converter_uses_exact_cross_rates_and_target_rounding(): void
    {
        $usd = $this->bootstrapDefaultUsd();
        $this->createCurrency(['code' => 'SYP', 'exchange_rate' => '13000', 'decimals' => 0])->assertOk();
        $this->createCurrency(['code' => 'EUR', 'exchange_rate' => '0.92', 'decimals' => 2])->assertOk();
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $eur = Currency::where('code', 'EUR')->firstOrFail();

        $converter = app(CurrencyConverter::class);

        $this->assertSame('130065', (string) $converter->convert('10.005', $usd, $syp));
        $this->assertSame('10.01', (string) $converter->convert('10.005', $usd, $usd));
        $this->assertSame('0.71', (string) $converter->convert('10000', $syp, $eur));
        $this->assertSame('10.00', (string) $converter->convert('130000', $syp, $usd));
    }
}
