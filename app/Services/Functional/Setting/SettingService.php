<?php

namespace App\Services\Functional\Setting;

use App\Models\Setting;

class SettingService
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) static::get($key, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) static::get($key, $default);
    }

    public static function set(string $key, mixed $value): Setting
    {
        return Setting::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }

    public static function setMany(array $items): void
    {
        foreach ($items as $key => $value) {
            static::set($key, $value);
        }
    }
    public static function getCompanyLocation(): array
    {
        return [
            'company_lat' => (float) static::get('company_lat', 0),
            'company_lng' => (float) static::get('company_lng', 0),
            'attendance_radius_meters' => (int) static::get('attendance_radius_meters', 0),
        ];
    }

    public function companyLocation()
    {
        return response()->json([
            'data' => SettingService::getCompanyLocation(),
            'status' => true,
            'error' => null,
            'statusCode' => 200

        ]);
    }


     public static function getAttendanceTimes(): array
    {
        return [
            'attendance_checkin_time' => static::get('attendance_checkin_time', '08:00'),
            'attendance_checkout_time' => static::get('attendance_checkout_time', '16:00'),
            'attendance_grace_minutes' => (int) static::get('attendance_grace_minutes', 0),
        ];
    }

    public static function setAttendanceTimes(string $checkinTime, string $checkoutTime, int $graceMinutes = 0): void
    {
        static::setMany([
            'attendance_checkin_time' => $checkinTime,
            'attendance_checkout_time' => $checkoutTime,
            'attendance_grace_minutes' => $graceMinutes,
        ]);
    }

    public static function getReportAttachmentTips(): array
    {
        $value = static::get('report_attachment_tips', '[]');

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values(array_filter($decoded)) : [];
    }

    public static function setReportAttachmentTips(array $tips): void
    {
        $cleanTips = array_values(array_filter(array_map(function ($tip) {
            return trim((string) $tip);
        }, $tips)));

        static::set('report_attachment_tips', json_encode($cleanTips, JSON_UNESCAPED_UNICODE));
    }

    public static function getAllConfig(): array
    {
        return [
            'company_location' => [
                'company_lat' => (float) static::get('company_lat', 0),
                'company_lng' => (float) static::get('company_lng', 0),
                'attendance_radius_meters' => (int) static::get('attendance_radius_meters', 0),
            ],

            'attendance_schedule' => [
                'attendance_checkin_time' => static::get('attendance_checkin_time', '08:00'),
                'attendance_checkout_time' => static::get('attendance_checkout_time', '16:00'),
                'attendance_grace_minutes' => (int) static::get('attendance_grace_minutes', 0),
                'weekly_off_days' => static::getWeeklyOffDays(),
            ],

            'report_settings' => [
                'report_attachment_tips' => static::getReportAttachmentTips(),
            ],
        ];
    }

    public static function getWeeklyOffDays(): array
    {
        $value = static::get('weekly_off_days', '[]');

        $decoded = json_decode((string) $value, true);

        return is_array($decoded)
            ? array_values(array_filter(array_map('strtolower', $decoded)))
            : [];
    }

    public static function setWeeklyOffDays(array $days): void
    {
        $cleanDays = array_values(array_filter(array_map(function ($day) {
            return strtolower(trim((string) $day));
        }, $days)));

        static::set('weekly_off_days', json_encode($cleanDays, JSON_UNESCAPED_UNICODE));
    }
}
