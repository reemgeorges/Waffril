<?php

namespace Database\Seeders\Essential;

use App\Models\Currency;
use App\Services\Currency\CurrencyManagement;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * WAFFRLI (2026-11-01) — currencies from EXPLICIT configuration only
 * (config/waffrli.php → WAFFRLI_DEFAULT_CURRENCY / WAFFRLI_EXTRA_CURRENCIES).
 *
 * - Nothing configured → nothing created (the operator adds currencies in
 *   the admin panel). No exchange rate is ever invented or fetched.
 * - The default is initialized only when NO default exists yet; an existing
 *   default is never switched (re-denomination is not implemented).
 * - Existing currencies are never modified.
 */
class ConfiguredCurrencySeeder extends Seeder
{
    /** @param  array{default: ?string, extra: array<string, array{rate: string, decimals: int}>}|null  $override  used by the demo seeder */
    public function __construct(protected ?array $override = null) {}

    public function run(): void
    {
        $config = $this->override ?? [
            'default' => config('waffrli.seed.default_currency'),
            'extra' => self::parseExtra((string) config('waffrli.seed.extra_currencies')),
        ];
        $management = app(CurrencyManagement::class);

        $default = $config['default'] ? strtoupper((string) $config['default']) : null;
        if ($default && ! CurrencyManagement::hasDefault()) {
            $currency = Currency::query()->where('code', $default)->first()
                ?? $management->create(self::attributes($default, '1', null));
            $management->initializeDefault($currency);
        } elseif (! $default) {
            $this->command?->info('No default currency configured (WAFFRLI_DEFAULT_CURRENCY): no currency was created here.');
        }

        if (! CurrencyManagement::hasDefault()) {
            return;
        }

        foreach ($config['extra'] as $code => $row) {
            if (! Currency::query()->where('code', $code)->exists()) {
                $management->create(self::attributes($code, $row['rate'], $row['decimals']));
            }
        }
    }

    /** "SYP:13000:0,EUR:0.92:2" → ['SYP' => ['rate' => '13000', 'decimals' => 0], …] */
    public static function parseExtra(string $value): array
    {
        $result = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $entry) {
            $parts = explode(':', $entry);
            $code = strtoupper(trim($parts[0] ?? ''));
            $rate = trim($parts[1] ?? '');
            if (! preg_match('/^[A-Z]{3}$/', $code) || ! is_numeric($rate) || (float) $rate <= 0) {
                throw new InvalidArgumentException("Invalid currency entry [{$entry}]: expected CODE:RATE[:DECIMALS] with a positive rate.");
            }
            $result[$code] = ['rate' => $rate, 'decimals' => isset($parts[2]) ? (int) $parts[2] : null];
        }

        return $result;
    }

    protected static function attributes(string $code, string $rate, ?int $decimals): array
    {
        $known = config("waffrli.currency_catalogue.{$code}", ['name' => ['en' => $code, 'ar' => $code], 'symbol' => $code, 'decimals' => 2]);

        return [
            'code' => $code,
            'name' => $known['name'],
            'symbol' => $known['symbol'],
            'decimals' => $decimals ?? $known['decimals'],
            'exchange_rate' => $rate,
            'is_active' => true,
        ];
    }
}
