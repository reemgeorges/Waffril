<?php

namespace Tests\Unit;

use App\Filament\Support\Fields;
use App\Models\Currency;
use PHPUnit\Framework\TestCase;

/** WAFFRLI (2026-11-04) — admin money display uses each currency's own decimals. */
class MoneyDisplayTest extends TestCase
{
    public function test_amounts_are_shown_in_their_currency_precision(): void
    {
        $usd = (new Currency)->forceFill(['code' => 'USD', 'decimals' => 2]);
        $syp = (new Currency)->forceFill(['code' => 'SYP', 'decimals' => 0]);

        // Numbers only: the localized currency label needs the app and is
        // covered by Tests\Feature\Currency\OperationalMoneyTest.
        $this->assertSame('4.58', Fields::formatAmount('4.5800', $usd));
        $this->assertSame('1,234,567.56', Fields::formatAmount('1234567.555', $usd));
        $this->assertSame('−2.50', Fields::formatAmount('-2.5', $usd));
        $this->assertSame('66,127', Fields::formatAmount('66127.0000', $syp));
        $this->assertSame('123,456,789,012,345', Fields::formatAmount('123456789012345.4', $syp));
        $this->assertSame('0', Fields::formatAmount('-0.2', $syp));
        $this->assertSame('—', Fields::formatAmount(null, $usd));
        $this->assertSame('—', Fields::formatMoney(null, $usd));
    }
}
