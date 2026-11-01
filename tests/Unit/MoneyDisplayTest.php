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

        $this->assertSame('USD 4.58', Fields::formatMoney('4.5800', $usd));
        $this->assertSame('USD 1,234,567.56', Fields::formatMoney('1234567.555', $usd));
        $this->assertSame('USD −2.50', Fields::formatMoney('-2.5', $usd));
        $this->assertSame('SYP 66,127', Fields::formatMoney('66127.0000', $syp));
        $this->assertSame('—', Fields::formatMoney(null, $usd));
    }
}
