<?php

namespace Tests\Support;

use App\Models\Currency;
use App\Services\Functional\Currency\CurrencyConverter;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-11-16) — puts a currency into the "missing / invalid
 * exchange rate" state for fail-closed tests, on every database driver.
 *
 * MySQL 8 enforces CHECK chk_currencies_rate_positive (exchange_rate > 0), so
 * a zero rate cannot be stored there at all: this asserts that refusal (the
 * strongest guarantee) and then hands the converter the invalid rate in
 * memory, so the application's own fail-closed guard is still exercised.
 * SQLite does not enforce the CHECK: the zero rate is stored, as before.
 */
trait InvalidatesCurrencyRate
{
    protected function makeRateInvalid(Currency $currency): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $currency->update(['exchange_rate' => '0']);

            return;
        }

        try {
            DB::transaction(fn () => Currency::query()->whereKey($currency->getKey())->update(['exchange_rate' => 0]));
            $this->fail('MySQL must refuse a non-positive exchange rate.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_currencies_rate_positive', $e->getMessage());
        }

        $invalidId = (int) $currency->getKey();
        $this->app->instance(CurrencyConverter::class, new class($invalidId) extends CurrencyConverter
        {
            public function __construct(private int $invalidId) {}

            public function crossRate(Currency $from, Currency $to): BigDecimal
            {
                $zero = fn (Currency $c) => (int) $c->getKey() === $this->invalidId ? (clone $c)->forceFill(['exchange_rate' => '0']) : $c;

                return parent::crossRate($zero($from), $zero($to));
            }
        });
    }
}
