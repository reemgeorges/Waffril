<?php

namespace App\Filament\Support;

use App\Models\Currency;
use App\Services\Currency\OperationalMoney;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31) — shared admin form fields.
 */
final class Fields
{
    /**
     * Arabic + English inputs for a Spatie-translatable attribute ({ar, en}).
     * With $fillsSlug, leaving the English name fills an empty "slug" field
     * from it (UX redesign 2026-11-11), so nobody has to invent one.
     */
    public static function translatable(string $name, bool $required = true, bool $multiline = false, int $max = 150, bool $fillsSlug = false): Grid
    {
        $make = fn (string $locale) => ($multiline ? Textarea::make("{$name}.{$locale}")->rows(3) : TextInput::make("{$name}.{$locale}")->maxLength($max))
            ->label(__("admin.fields.{$name}").' ('.__("admin.languages.{$locale}").')')
            // Each language is typed in its own direction, whatever the panel language.
            ->extraInputAttributes(['dir' => $locale === 'ar' ? 'rtl' : 'ltr', 'lang' => $locale])
            ->required($required)
            ->when($fillsSlug && $locale === 'en', fn ($input) => $input->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if (blank($get('slug')) && filled($state)) {
                        $set('slug', Str::slug($state));
                    }
                }));

        return Grid::make(2)->schema([$make('ar'), $make('en')]);
    }

    /**
     * The URL name (slug): filled from the English name when left empty,
     * kept in a collapsed "More settings" section because staff rarely need
     * it. Validation is unchanged (required, unique, letters/digits/dashes).
     */
    public static function slug(string $table): TextInput
    {
        // Optional: the English name is required, and an empty URL name is
        // generated from it when saving (made unique). Not marking it required
        // also keeps the browser from blocking a fast "Save" before the
        // automatic fill has come back from the server.
        return TextInput::make('slug')->label(__('admin.fields.slug'))
            ->maxLength(120)->alphaDash()->unique($table, 'slug', ignoreRecord: true)
            ->extraInputAttributes(['dir' => 'ltr'])
            ->dehydrateStateUsing(fn (?string $state, Get $get, ?Model $record) => filled($state)
                ? $state
                : self::uniqueSlug($table, (string) $get('name.en'), $record?->getKey()))
            ->helperText(__('admin.help.slug'));
    }

    /** "Levantine starters" → "levantine-starters" (or "-2", "-3", … when taken). */
    public static function uniqueSlug(string $table, string $source, mixed $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        for ($i = 2; DB::table($table)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * Table search on a related customer (UX overhaul 2026-11-12): phone,
     * first or last name, e-mail. $relation is the path to the User, e.g.
     * "user", "order.user", "wallet.user".
     */
    public static function searchCustomer(string $relation): Closure
    {
        return fn (Builder $query, string $search) => $query->whereHas($relation, fn ($user) => $user
            ->where('phone', 'like', "%{$search}%")
            ->orWhere('first_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%"));
    }

    /** A collapsed section for rarely changed settings (progressive disclosure). */
    public static function moreSettings(array $schema): Section
    {
        return Section::make(__('admin.forms.more_settings'))
            ->description(__('admin.forms.more_settings_help'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->collapsible()->collapsed(fn (?Model $record) => $record !== null)
            ->schema($schema);
    }

    public static function phone(string $name): TextInput
    {
        return TextInput::make($name)
            ->label(__("admin.fields.{$name}"))
            ->tel()
            ->regex('/^\+[1-9]\d{6,14}$/')
            ->helperText(__('admin.help.e164'))
            ->maxLength(16);
    }

    /**
     * A catalog amount STORED in the accounting base currency (prices,
     * option deltas, minimum orders, delivery fees, PLUS amounts, fixed
     * discounts). The column and its currency never change.
     *
     * SYP operation (2026-11-15, OperationalMoney):
     *  - rate approved: the admin types the OPERATIONAL amount (SYP). It is
     *    shown converted with the customer catalog's own conversion, saved
     *    as the exact base amount that converts back to the typed value, and
     *    rejected (with the nearest storable values) when no such base
     *    amount exists at 4 decimals. An unchanged value keeps the stored
     *    base amount untouched, so reopening and saving never drifts.
     *  - rate not approved (or missing): the admin types the base amount as
     *    before, with a live hint of the operational equivalent marked as
     *    unconfirmed.
     *
     * $fixedWhen: the name of a percent|fixed type field; the amount is
     * monetary only while that field is "fixed" (percentages are untouched).
     */
    public static function money(string $name, bool $required = true, ?string $fixedWhen = null): TextInput
    {
        $monetary = fn (Get $get): bool => $fixedWhen === null || $get($fixedWhen) === 'fixed';
        $input = TextInput::make($name)
            ->label(__("admin.fields.{$name}"))
            ->numeric()
            ->minValue(0)
            ->required($required);

        if (! OperationalMoney::inputEnabled()) {
            $base = self::defaultCurrency();

            return $input
                ->rule(fn (Get $get) => $monetary($get) ? 'decimal:0,'.min(4, $base?->decimals ?? 4) : 'decimal:0,4')
                ->suffix(fn (Get $get) => $monetary($get) ? $base?->code : null)
                ->live(onBlur: true)
                ->hint(fn (Get $get, $state) => $monetary($get) ? self::operationalPreview($state) : null)
                ->hintColor('warning');
        }

        $operational = OperationalMoney::currency();

        return $input
            // Typed in the DISPLAYED denomination (new SYP: up to 2 decimals = qirsh).
            ->rule(fn (Get $get) => $monetary($get) ? 'decimal:0,'.(int) Denomination::decimals($operational) : 'decimal:0,4')
            ->suffix(fn (Get $get) => $monetary($get) ? self::currencyLabel($operational) : null)
            ->afterStateHydrated(function (TextInput $component, $state, Get $get) use ($monetary): void {
                if ($state !== null && $state !== '' && $monetary($get)) {
                    $component->state(Denomination::presentString(OperationalMoney::fromBase((string) $state), OperationalMoney::currency()));
                }
            })
            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get, $monetary): void {
                if (! $monetary($get) || $value === null || $value === '' || ! is_numeric($value)) {
                    return;
                }
                $result = OperationalMoney::toBase((string) $value);
                if ($result['exact'] === null) {
                    $fail(self::notStorableMessage($result));
                }
            })
            ->dehydrateStateUsing(function ($state, Get $get, ?Model $record) use ($name, $fixedWhen, $monetary) {
                if ($state === null || $state === '' || ! $monetary($get)) {
                    return $state;
                }
                // Unchanged value (same type): keep the stored base amount as is.
                $original = $record?->getRawOriginal($name);
                $sameType = $fixedWhen === null || $record?->getRawOriginal($fixedWhen) === 'fixed';
                if ($original !== null && $sameType) {
                    $shown = Denomination::present(OperationalMoney::fromBase((string) $original), OperationalMoney::currency());
                    if ($shown !== null && $shown->isEqualTo((string) $state)) {
                        return $original;
                    }
                }

                return OperationalMoney::toBase((string) $state)['exact']
                    ?? throw new \LogicException('validated amount is not storable');
            })
            ->hintIcon('heroicon-m-information-circle', tooltip: fn (Get $get, ?Model $record) => $monetary($get) && $record?->getRawOriginal($name) !== null
                ? self::storedTooltip($record->getRawOriginal($name))
                : null);
    }

    /**
     * An amount typed in a RECORD's own currency (refunds, settlements,
     * cancellation cash): typed in the displayed denomination (new SYP, up
     * to 2 decimals) and handed to the services in stored units (old SYP),
     * converted exactly once here. Use presentedDefault()/presentedMax()
     * for stored defaults and limits.
     */
    public static function recordMoney(string $name, ?Currency $currency): TextInput
    {
        return TextInput::make($name)->label(__("admin.fields.{$name}"))
            ->numeric()
            ->rule('decimal:0,'.(int) Denomination::decimals($currency, 4))
            ->suffix(self::currencyLabel($currency))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' || ! is_numeric($state)
                ? $state
                : (string) (Denomination::toStored((string) $state, $currency) ?? throw new \LogicException('validated amount has too many decimals')));
    }

    /** A stored amount as the value of a recordMoney() input. */
    public static function presentedDefault(mixed $stored, ?Currency $currency): string
    {
        return Denomination::presentString((string) ($stored ?? '0'), $currency) ?? '0';
    }

    /** "≈ 15,001 ل.س · rate not confirmed" for a base amount being typed. */
    public static function operationalPreview(mixed $baseAmount): ?string
    {
        if (OperationalMoney::sameAsBase() || $baseAmount === null || $baseAmount === '' || ! is_numeric($baseAmount)) {
            return null;
        }
        $converted = OperationalMoney::fromBase((string) $baseAmount);
        if ($converted === null) {
            return __('admin.money.no_operational_rate');
        }
        $text = '≈ '.self::formatMoney((string) $converted, OperationalMoney::currency());

        return OperationalMoney::rateApproved() ? $text : $text.' · '.__('admin.money.rate_unconfirmed');
    }

    /**
     * A base-stored catalog amount for tables and infolists: the operational
     * amount customers are charged ("15,001 ل.س"), never the base amount
     * relabelled. "—" for null; an explicit unavailable text without a rate.
     */
    public static function catalogMoney(mixed $baseAmount, ?int $sourceCurrencyId = null): string
    {
        if ($baseAmount === null || $baseAmount === '') {
            return '—';
        }
        if (OperationalMoney::currency() === null) {
            return __('admin.money.no_operational_rate');
        }
        $converted = $sourceCurrencyId !== null
            ? OperationalMoney::fromCurrency((string) $baseAmount, Currency::find($sourceCurrencyId))
            : OperationalMoney::fromBase((string) $baseAmount);

        return $converted === null
            ? __('admin.money.no_operational_rate')
            : self::formatMoney((string) $converted, OperationalMoney::currency());
    }

    /**
     * A table column for a base-stored catalog amount: the operational
     * amount (what customers pay), with the stored base value and rate in a
     * tooltip for audit. $signed adds "+" to positive amounts (option deltas).
     */
    public static function catalogMoneyColumn(TextColumn $column, bool $signed = false): TextColumn
    {
        $name = $column->getName();

        return $column
            ->formatStateUsing(fn ($state) => ($signed && is_numeric($state) && Money::of((string) $state)->isPositive() ? '+' : '').self::catalogMoney($state))
            ->tooltip(fn (?Model $record) => self::storedTooltip($record?->getRawOriginal($name)));
    }

    /**
     * Audit detail for a base-stored amount (tooltip): the stored base value
     * and the rate used, with its approval state. Null when base and
     * operational currencies are the same.
     */
    public static function storedTooltip(mixed $baseAmount): ?string
    {
        if ($baseAmount === null || $baseAmount === '' || OperationalMoney::sameAsBase()) {
            return null;
        }
        $base = self::defaultCurrency();
        $rate = OperationalMoney::rate();

        return __('admin.money.stored_as', [
            'amount' => trim(($base?->code ?? '').' '.self::formatDecimal((string) $baseAmount)),
            // In the displayed denomination (130 new SYP per USD, stored as 13,000 old).
            'rate' => $rate !== null ? self::formatDecimal(Denomination::presentRate((string) $rate, OperationalMoney::currency())) : '—',
            'status' => OperationalMoney::rateApproved() ? __('admin.money.rate_approved') : __('admin.money.rate_unconfirmed'),
        ]);
    }

    /** @param array{exact: ?string, lower: mixed, upper: mixed} $result */
    protected static function notStorableMessage(array $result): string
    {
        $currency = OperationalMoney::currency();
        $options = collect([$result['lower'], $result['upper']])->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => self::formatMoney((string) $v, $currency))->implode(' / ');

        return $options === ''
            ? __('admin.money.no_operational_rate')
            : __('admin.money.not_storable', ['options' => $options]);
    }

    /** "ل.س" in Arabic, the ISO code otherwise. */
    public static function currencyLabel(?Currency $currency): string
    {
        if ($currency === null) {
            return '';
        }
        if (app()->getLocale() === 'ar') {
            if (preg_match('/\p{Arabic}/u', (string) $currency->symbol)) {
                return (string) $currency->symbol;
            }

            return (string) (config('waffrli.currency.arabic_symbols.'.$currency->code) ?? $currency->code);
        }

        return (string) $currency->code;
    }

    /** The platform default currency (one query per request). */
    public static function defaultCurrency(): ?Currency
    {
        return once(fn () => Currency::query()->default()->first());
    }

    /**
     * For modal Edit/View actions: replaces each Spatie-translatable
     * attribute's current-locale string with all its translations so the
     * {ar, en} inputs are filled.
     */
    public static function withTranslations(): Closure
    {
        return function (array $data, Model $record): array {
            if (method_exists($record, 'getTranslatableAttributes')) {
                foreach ($record->getTranslatableAttributes() as $attribute) {
                    $data[$attribute] = $record->getTranslations($attribute);
                }
            }

            return $data;
        };
    }

    /**
     * Display a stored amount (4-decimal storage scale) in its currency:
     * the currency's own decimals, thousands separator, ISO code
     * ("USD 4.58", "SYP 66,127"). Display only: stored values are untouched.
     */
    /**
     * Wraps a left-to-right value (phone, e-mail, code) in Unicode direction
     * isolates, so "+963 9…" stays "+963 9…" inside Arabic text (table UX
     * overhaul 2026-11-12). Display only; null stays null.
     */
    public static function ltr(?string $value): ?string
    {
        return filled($value) ? "\u{2066}{$value}\u{2069}" : null;
    }

    /**
     * A stored amount in ITS OWN currency (2026-11-15: "15,000 ل.س" in
     * Arabic, "15,000 SYP" / "3.34 USD" in English), isolated left-to-right
     * like the mobile apps. Never converts: a USD record stays USD.
     */
    public static function formatMoney(mixed $amount, ?Currency $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $label = self::currencyLabel($currency);

        return "\u{2066}".trim(self::formatAmount($amount, $currency).' '.$label)."\u{2069}";
    }

    /** The number only, at the currency's decimals with thousands separators ("66,127", "−2.50"). */
    public static function formatAmount(mixed $amount, ?Currency $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        // Stored units → displayed denomination (old → new SYP), exactly once.
        $presented = Denomination::present((string) $amount, $currency);
        $decimals = (int) Denomination::decimals($currency);
        $value = (string) Money::roundTo($presented, $decimals);
        [$int, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $grouped = strrev(implode(',', str_split(strrev($int), 3)));
        $negative = str_starts_with($value, '-') && ! Money::of($value)->isZero();
        // A redenominated currency shows its fraction (qirsh) only when there is one.
        $showFraction = $decimals > 0 && ! (Denomination::converts($currency) && trim($fraction, '0') === '');

        return ($negative ? '−' : '').$grouped.($showFraction ? '.'.str_pad($fraction, $decimals, '0') : '');
    }

    /**
     * Audit note for a stored amount whose stored denomination differs from
     * the displayed one: "Stored as 32,500 old SYP". Null otherwise.
     */
    public static function storedDenominationNote(mixed $amount, ?Currency $currency): ?string
    {
        if ($amount === null || $amount === '' || ! Denomination::converts($currency)) {
            return null;
        }

        return __('admin.money.stored_denomination', [
            'amount' => self::formatDecimal((string) $amount),
            'code' => $currency->code,
            'denomination' => __('admin.money.denomination_'.Denomination::storedName($currency)),
        ]);
    }

    /**
     * True when a record's currency is not the operational one (e.g. a USD
     * order placed before SYP operation): shown in its own currency with a
     * note, never relabelled or converted without a recorded rate.
     */
    public static function isHistoricalCurrency(?Currency $currency): bool
    {
        $operational = OperationalMoney::currency();

        return $currency !== null && $operational !== null && ! $currency->is($operational);
    }

    /** The table note for a record kept in a non-operational currency (null otherwise). */
    public static function historicalCurrencyNote(?Currency $currency): ?string
    {
        return self::isHistoricalCurrency($currency)
            ? __('admin.money.historical_currency', ['code' => $currency->code])
            : null;
    }

    /**
     * Display a stored decimal without its storage padding: thousands
     * separator, trailing fraction zeros removed, nothing rounded
     * ("1.000000000000" → "1", "13000.500000000000" → "13,000.5",
     * "7.140" → "7.14"). String-based, so no float precision is lost.
     * Display only: stored values and calculations are untouched.
     */
    public static function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $text = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return $text;
        }
        $negative = str_starts_with($text, '-');
        [$int, $fraction] = array_pad(explode('.', ltrim($text, '-')), 2, '');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $grouped = strrev(implode(',', str_split(strrev($int), 3)));
        $fraction = rtrim($fraction, '0');

        return ($negative && ($int !== '0' || $fraction !== '') ? '−' : '').$grouped.($fraction !== '' ? '.'.$fraction : '');
    }
}
