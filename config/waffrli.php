<?php

/**
 * WAFFRLI (2026-11-01) — seeding configuration.
 *
 * The essential seeders never invent business data: the default currency
 * and every extra currency (with its exchange rate) are created only when
 * they are explicitly configured here. Rates are "units per 1 default
 * unit" and are entered by an operator, never fetched or guessed.
 *
 *   WAFFRLI_DEFAULT_CURRENCY=USD
 *   WAFFRLI_EXTRA_CURRENCIES="SYP:13000:0,EUR:0.92:2"   (code:rate:decimals)
 */
return [
    'seed' => [
        'default_currency' => env('WAFFRLI_DEFAULT_CURRENCY'),
        'extra_currencies' => env('WAFFRLI_EXTRA_CURRENCIES'),
    ],

    /** Known display metadata for configured currency codes. */
    'currency_catalogue' => [
        'USD' => ['name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'], 'symbol' => '$', 'decimals' => 2],
        'SYP' => ['name' => ['en' => 'Syrian Pound', 'ar' => 'ليرة سورية'], 'symbol' => 'ل.س', 'decimals' => 0],
        'EUR' => ['name' => ['en' => 'Euro', 'ar' => 'يورو'], 'symbol' => '€', 'decimals' => 2],
        'TRY' => ['name' => ['en' => 'Turkish Lira', 'ar' => 'ليرة تركية'], 'symbol' => '₺', 'decimals' => 2],
    ],

    'driver_map' => [
        // A stored position older than this is shown as stale on the map.
        'stale_after_minutes' => (int) env('WAFFRLI_DRIVER_LOCATION_STALE_MINUTES', 10),
        'refresh_seconds' => (int) env('WAFFRLI_DRIVER_MAP_REFRESH_SECONDS', 30),
        // OpenStreetMap standard tiles. Their usage policy requires the
        // attribution below and forbids heavy use: for production traffic
        // configure your own tile server or a commercial provider here.
        'tile_url' => env('WAFFRLI_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'tile_attribution' => env('WAFFRLI_MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'),
    ],

    /*
     * Customer identity (2026-11-03). Phone OTP only for customers.
     */
    'customer' => [
        // National numbers starting with 0 (e.g. 0912 345 678) are normalized with this country code.
        'default_country_code' => env('WAFFRLI_DEFAULT_COUNTRY_CODE', '963'),
        'otp_channel' => 'sms',
        // When SMS delivery fails, retry once over WhatsApp.
        'whatsapp_fallback' => (bool) env('WAFFRLI_OTP_WHATSAPP_FALLBACK', true),
        'registration_token_ttl_minutes' => 15,
        'recovery_days' => 30,
        // The legacy email/password /api/v1/user/* customer flow is replaced
        // by phone OTP (/api/v1/customer/*). It stays loadable only when this
        // is explicitly enabled (the legacy starter-kit test-suite does).
        'legacy_password_auth' => (bool) env('WAFFRLI_LEGACY_USER_AUTH', false),
    ],

    /*
     * Text-message transport for OTPs. `fake` (default) never sends anything
     * and is refused in production; `twilio` needs real credentials in the
     * environment. Credentials are never committed.
     */
    'messaging' => [
        'driver' => env('WAFFRLI_SMS_DRIVER', 'fake'),
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'sms_from' => env('TWILIO_SMS_FROM'),
            'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
            'timeout' => 10,
        ],
    ],

    /*
     * Development-only helpers. The OTP peek endpoint exists ONLY when the
     * environment is local/testing, the fake transport is active AND this
     * token is set; it is never registered in production.
     */
    /*
    | Push notifications (FCM HTTP v1). Credentials ONLY from the environment:
    | either FIREBASE_CREDENTIALS (path to a service-account JSON kept outside
    | the repository) or FIREBASE_PROJECT_ID + FIREBASE_CLIENT_EMAIL +
    | FIREBASE_PRIVATE_KEY. Driver `fake` records messages in memory (refused
    | in production); nothing is pushed while `enabled` is false.
    */
    'push' => [
        'enabled' => (bool) env('WAFFRLI_PUSH_ENABLED', false),
        'driver' => env('WAFFRLI_PUSH_DRIVER', 'fake'),
        'queue' => env('WAFFRLI_PUSH_QUEUE', 'default'),
        'tries' => 3,
        // Skip the push when the recipient's private Reverb channel is occupied
        // (the app is open and already received the broadcast).
        'skip_when_online' => (bool) env('WAFFRLI_PUSH_SKIP_WHEN_ONLINE', true),
        'max_driver_devices' => 3,
        'fcm' => [
            'credentials' => env('FIREBASE_CREDENTIALS'),
            'project_id' => env('FIREBASE_PROJECT_ID'),
            'client_email' => env('FIREBASE_CLIENT_EMAIL'),
            'private_key' => env('FIREBASE_PRIVATE_KEY'),
            'timeout' => 10,
        ],
        'marketing_chunk' => 500,
    ],

    'dev' => [
        'otp_peek_token' => env('WAFFRLI_DEV_OTP_PEEK_TOKEN'),
    ],
];
