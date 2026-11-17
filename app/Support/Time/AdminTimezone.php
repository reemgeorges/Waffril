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

    /** A valid IANA identifier (case-sensitive, as browsers report it), else null. */
    public static function valid(?string $zone): ?string
    {
        if ($zone === null || $zone === '' || strlen($zone) > 64) {
            return null;
        }

        return in_array($zone, DateTimeZone::listIdentifiers(), true) || $zone === 'UTC' ? $zone : null;
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
        $zone = self::valid($request->cookie(self::COOKIE)) ?? self::fallback();
        app()->instance(self::BINDING, $zone);

        return $zone;
    }

    /** The zone of the current panel request; the fallback anywhere else (console, queue). */
    public static function current(): string
    {
        $zone = app()->bound(self::BINDING) ? app(self::BINDING) : null;

        return is_string($zone) ? $zone : self::fallback();
    }

    /** Forgets the zone resolved for the current request (tests). */
    public static function reset(): void
    {
        app()->forgetInstance(self::BINDING);
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
