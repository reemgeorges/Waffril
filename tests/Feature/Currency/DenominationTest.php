<?php

namespace Tests\Feature\Currency;

use App\Models\Currency;
use App\Support\Money\Denomination;
use Tests\TestCase;

/**
 * New Syrian pound (2026-11-15): 100 old SYP = 1 new SYP. Stored SYP is old,
 * displayed SYP is new; a fixed ratio, never an exchange rate.
 */
class DenominationTest extends TestCase
{
    protected function syp(): Currency
    {
        return (new Currency)->forceFill(['code' => 'SYP', 'decimals' => 0, 'symbol' => 'ل.س']);
    }

    public function test_old_stored_pounds_are_shown_as_new_pounds_exactly(): void
    {
        $syp = $this->syp();
        $this->assertSame('100', (string) Denomination::factor($syp));
        $this->assertSame('new', Denomination::displayName($syp));
        $this->assertSame('old', Denomination::storedName($syp));
        $this->assertSame(2, Denomination::decimals($syp));

        $this->assertSame('325.00', Denomination::presentString('32500.0000', $syp));
        $this->assertSame('0.01', Denomination::presentString('1', $syp), '1 old pound = 1 qirsh');
        $this->assertSame('1234567.89', Denomination::presentString('123456789', $syp));
        // A sub-qirsh ledger remainder is kept exactly, never rounded away.
        $this->assertSame('10.005', Denomination::presentString('1000.5', $syp));
        $this->assertNull(Denomination::presentString(null, $syp));

        // Typed new pounds → stored old pounds; more than 2 decimals is not an amount.
        $this->assertSame('32500', (string) Denomination::toStored('325', $syp));
        $this->assertSame('1050', (string) Denomination::toStored('10.50', $syp));
        $this->assertNull(Denomination::toStored('10.555', $syp));
        foreach (['0', '0.01', '99.99', '125000', '1234567.89'] as $typed) {
            $this->assertTrue(Denomination::present(Denomination::toStored($typed, $syp), $syp)->isEqualTo($typed), "round trip {$typed}");
        }

        // Rates are restated by the same ratio: 13000 old per USD = 130 new per USD.
        $this->assertSame('130', Denomination::presentRate('13000.000000000000', $syp));
        $this->assertSame(['code' => 'SYP', 'symbol' => 'ل.س', 'decimals' => 2, 'denomination' => 'new'], Denomination::currencyPayload($syp));
    }

    public function test_other_currencies_and_already_new_data_are_never_converted(): void
    {
        $usd = (new Currency)->forceFill(['code' => 'USD', 'decimals' => 2]);
        $this->assertSame('1', (string) Denomination::factor($usd));
        $this->assertFalse(Denomination::converts($usd));
        $this->assertSame('3.34', Denomination::presentString('3.3400', $usd));
        $this->assertSame('3.34', (string) Denomination::toStored('3.34', $usd));
        $this->assertNull(Denomination::displayName($usd));

        // If the stored SYP data is ever redenominated, declaring it "new" makes
        // the factor 1: the same amount is not divided a second time.
        config(['waffrli.currency.denominations.SYP.stored' => 'new']);
        $syp = $this->syp();
        $this->assertFalse(Denomination::converts($syp));
        $this->assertSame('325.00', Denomination::presentString('325', $syp));
        $this->assertSame('325', (string) Denomination::toStored('325', $syp));

        // Showing old pounds (if ever configured) is the identity on old data.
        config(['waffrli.currency.denominations.SYP.stored' => 'old', 'waffrli.currency.denominations.SYP.display' => 'old']);
        $this->assertFalse(Denomination::converts($syp));
        $this->assertSame(0, Denomination::decimals($syp));
        $this->assertSame('32500', Denomination::presentString('32500.0000', $syp));
    }
}
