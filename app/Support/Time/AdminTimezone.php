<?php

namespace App\Support\Time;

use App\Support\Settings\PlatformSettings;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * WAFFRLI (2026-11-17) — the timezone the admin panel DISPLAYS times in.
 *
 * - Storage is unchanged: config('app.timezone') is UTC, every timestamp is
 *   stored and compared in UTC, and nothing here rewrites a stored value.
 * - Each admin sees their own browser/device zone: the panel's head script
 *   writes the browser's IANA zone (Intl.DateTimeFormat().resolvedOptions())
 *   into the `waffrli_tz` cookie; SetAdminDisplayTimezone reads it on every
 *   panel request, Livewire updates included, and hands it to Filament
 *   (FilamentTimezone), which converts every date column, entry and
 *   date-time picker (input converted back to UTC on save).
 * - Admins in different zones each get their own conversion (per browser);
 *   DST follows the IANA rules (never a fixed offset).
 * - Fallback when the browser zone is unknown (first request, cookie
 *   blocked, invalid value): WAFFRLI_ADMIN_DISPLAY_TIMEZONE, else the
 *   platform operations timezone (setting operations.timezone), else UTC.
 */
final class AdminTimezone
{
    public const COOKIE = 'waffrli_tz';

    /**
     * Kept in the container for the current request. Not on the request
     * object: Livewire runs persistent middleware against a separate request
     * built from the original route, and the component must still see it.
     * Not static: the container is rebuilt per test/process, and every panel
     * request sets it again.
     */
    private const BINDING = 'waffrli.admin_timezone';

    /**
     * Legacy IANA names some browsers and OS versions still report, mapped
     * to the canonical zone (tzdata "backward" file). Needed because PHP
     * builds that use the system tzdata may not know these links at all.
     */
    private const ALIASES = [
        'Asia/Calcutta' => 'Asia/Kolkata', 'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh', 'Asia/Rangoon' => 'Asia/Yangon',
        'Asia/Dacca' => 'Asia/Dhaka', 'Asia/Thimbu' => 'Asia/Thimphu',
        'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar', 'Asia/Ashkhabad' => 'Asia/Ashgabat',
        'Asia/Macao' => 'Asia/Macau', 'Asia/Ujung_Pandang' => 'Asia/Makassar',
        'Asia/Chongqing' => 'Asia/Shanghai', 'Asia/Chungking' => 'Asia/Shanghai',
        'Asia/Harbin' => 'Asia/Shanghai', 'Asia/Kashgar' => 'Asia/Urumqi',
        'Asia/Tel_Aviv' => 'Asia/Jerusalem', 'Asia/Istanbul' => 'Europe/Istanbul',
        'Europe/Kiev' => 'Europe/Kyiv', 'Europe/Uzhgorod' => 'Europe/Kyiv',
        'Europe/Zaporozhye' => 'Europe/Kyiv', 'Europe/Belfast' => 'Europe/London',
        'Europe/Nicosia' => 'Asia/Nicosia', 'Europe/Tiraspol' => 'Europe/Chisinau',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
        'America/Catamarca' => 'America/Argentina/Catamarca',
        'America/Cordoba' => 'America/Argentina/Cordoba',
        'America/Jujuy' => 'America/Argentina/Jujuy',
        'America/Mendoza' => 'America/Argentina/Mendoza',
        'America/Indianapolis' => 'America/Indiana/Indianapolis',
        'America/Fort_Wayne' => 'America/Indiana/Indianapolis',
        'America/Louisville' => 'America/Kentucky/Louisville',
        'America/Godthab' => 'America/Nuuk', 'America/Montreal' => 'America/Toronto',
        'America/Shiprock' => 'America/Denver', 'America/Ensenada' => 'America/Tijuana',
        'America/Santa_Isabel' => 'America/Tijuana', 'America/Porto_Acre' => 'America/Rio_Branco',
        'America/Atka' => 'America/Adak', 'America/Virgin' => 'America/St_Thomas',
        'Atlantic/Faeroe' => 'Atlantic/Faroe', 'Atlantic/Jan_Mayen' => 'Europe/Oslo',
        'Pacific/Enderbury' => 'Pacific/Kanton', 'Pacific/Truk' => 'Pacific/Chuuk',
        'Pacific/Yap' => 'Pacific/Chuuk', 'Pacific/Ponape' => 'Pacific/Pohnpei',
        'Pacific/Samoa' => 'Pacific/Pago_Pago', 'Pacific/Johnston' => 'Pacific/Honolulu',
        'Africa/Asmera' => 'Africa/Nairobi', 'Africa/Timbuktu' => 'Africa/Abidjan',
        'Australia/ACT' => 'Australia/Sydney', 'Australia/Canberra' => 'Australia/Sydney',
        'Australia/NSW' => 'Australia/Sydney', 'Australia/North' => 'Australia/Darwin',
        'Australia/Queensland' => 'Australia/Brisbane', 'Australia/South' => 'Australia/Adelaide',
        'Australia/Tasmania' => 'Australia/Hobart', 'Australia/Victoria' => 'Australia/Melbourne',
        'Australia/West' => 'Australia/Perth',
        'US/Alaska' => 'America/Anchorage', 'US/Arizona' => 'America/Phoenix',
        'US/Central' => 'America/Chicago', 'US/Eastern' => 'America/New_York',
        'US/Hawaii' => 'Pacific/Honolulu', 'US/Mountain' => 'America/Denver',
        'US/Pacific' => 'America/Los_Angeles',
        'Etc/UTC' => 'UTC', 'Etc/UCT' => 'UTC', 'Etc/Universal' => 'UTC', 'Etc/Zulu' => 'UTC',
        'Etc/GMT' => 'UTC', 'Etc/GMT0' => 'UTC', 'Etc/GMT+0' => 'UTC', 'Etc/GMT-0' => 'UTC',
        'Etc/Greenwich' => 'UTC', 'GMT' => 'UTC', 'UCT' => 'UTC', 'Universal' => 'UTC', 'Zulu' => 'UTC',
    ];

    /**
     * The canonical zone for a browser-reported IANA name (legacy names
     * mapped), else null. Region/City names and UTC only: raw offsets
     * ("+03:00") and abbreviations ("EST") are refused.
     */
    public static function valid(?string $zone): ?string
    {
        if ($zone === null || $zone === '' || strlen($zone) > 64) {
            return null;
        }
        // Canonical name first; the name as sent if this PHP's tzdata is
        // older than the canonical one (e.g. knows Europe/Kiev, not Europe/Kyiv).
        foreach (array_unique([self::ALIASES[$zone] ?? $zone, $zone]) as $candidate) {
            if ($candidate !== 'UTC' && ! preg_match('~^[A-Z][A-Za-z_]*(?:/[A-Za-z0-9_+\-]+){1,2}$~', $candidate)) {
                continue;
            }
            try {
                new DateTimeZone($candidate);

                return $candidate;
            } catch (Throwable) {
                // try the next candidate
            }
        }

        return null;
    }

    public static function fallback(): string
    {
        foreach ([config('waffrli.admin_display_timezone'), self::platformZone()] as $candidate) {
            if ($zone = self::valid(is_string($candidate) ? $candidate : null)) {
                return $zone;
            }
        }

        return 'UTC';
    }

    /** Resolves and remembers the zone for this request. */
    public static function resolveFrom(Request $request): string
    {
        $reported = $request->cookie(self::COOKIE);
        $zone = self::valid(is_string($reported) ? $reported : null) ?? self::fallback();
        app()->instance(self::BINDING, $zone);
        app()->instance(self::BINDING.'.reported', is_string($reported) ? $reported : null);

        return $zone;
    }

    /** The zone of the current panel request; the fallback anywhere else (console, queue). */
    public static function current(): string
    {
        $zone = app()->bound(self::BINDING) ? app(self::BINDING) : null;

        return is_string($zone) ? $zone : self::fallback();
    }

    /**
     * The zone name the browser reported for this request, exactly as sent
     * (it may be a legacy alias of current()). The head script compares the
     * browser's zone with it, so an alias never triggers a reload.
     */
    public static function reported(): ?string
    {
        $zone = app()->bound(self::BINDING.'.reported') ? app(self::BINDING.'.reported') : null;

        return is_string($zone) ? $zone : null;
    }

    /** Forgets the zone resolved for the current request (tests). */
    public static function reset(): void
    {
        app()->forgetInstance(self::BINDING);
        app()->forgetInstance(self::BINDING.'.reported');
    }

    /** A copy of $value in the admin's zone (the instant is unchanged). */
    public static function local(?CarbonInterface $value): ?Carbon
    {
        return $value === null ? null : Carbon::instance($value)->setTimezone(self::current());
    }

    /** Localised display in the admin's zone, e.g. format($order->created_at, TableDefaults::dateTimeFormat()). */
    public static function format(?CarbonInterface $value, string $format): ?string
    {
        return self::local($value)?->locale(app()->getLocale())->translatedFormat($format);
    }

    /**
     * The UTC instant at which a LOCAL calendar day starts for this admin
     * ("2026-11-05" in Asia/Damascus starts 2026-11-04 21:00 UTC). Used by
     * date filters so a picked day means the admin's own day.
     */
    public static function dayStartUtc(string $localDate): Carbon
    {
        return Carbon::parse($localDate, self::current())->startOfDay()->utc();
    }

    /** The UTC instant at which the LOCAL day after $localDate starts (exclusive end of the day). */
    public static function dayEndUtc(string $localDate): Carbon
    {
        return Carbon::parse($localDate, self::current())->startOfDay()->addDay()->utc();
    }

    private static function platformZone(): ?string
    {
        try {
            return PlatformSettings::timezone();
        } catch (Throwable) {
            return null;   // e.g. before the settings table exists
        }
    }
}
