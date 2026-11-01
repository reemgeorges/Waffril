<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | By uncommenting the Laravel Echo configuration, you may connect Filament
    | to any Pusher-compatible websockets server.
    |
    | This will allow your users to receive real-time notifications.
    |
    */

    // WAFFRLI (2026-11-03): the admin panel joins the ONE shared Reverb
    // server (Laravel Echo bundled with Filament). Only the PUBLIC app key is
    // sent to the browser; the secret never leaves the server. Disabled (the
    // panel falls back to polling) unless BROADCAST_CONNECTION=reverb and
    // REVERB_APP_KEY are set. Channel auth: POST /broadcasting/auth
    // (admin_web session, routes/channels.php).
    'broadcasting' => [
        'echo' => env('BROADCAST_CONNECTION') === 'reverb' && filled(env('REVERB_APP_KEY')) ? [
            'broadcaster' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'wsHost' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST')),
            'wsPort' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 443)),
            'wssPort' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 443)),
            'forceTLS' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'https')) === 'https',
            'enabledTransports' => ['ws', 'wss'],
            'authEndpoint' => '/broadcasting/auth',
            'disableStats' => true,
        ] : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This is the storage disk Filament will use to store files. You may use
    | any of the disks defined in the `config/filesystems.php`.
    |
    */

    'default_filesystem_disk' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Temporary File URL Expiry
    |--------------------------------------------------------------------------
    |
    | When Filament generates temporary URLs for previewing private files
    | (file uploads, image columns, image entries, rich editor attachments,
    | etc.), this value controls how many minutes those URLs remain valid.
    |
    | The generated URL's expiry is rounded up to the end of the hour it
    | falls in, so the effective lifetime will be between this value and
    | this value plus up to 60 minutes.
    |
    */

    'temporary_file_url_expiry_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Assets Path
    |--------------------------------------------------------------------------
    |
    | This is the directory where Filament's assets will be published to. It
    | is relative to the `public` directory of your Laravel application.
    |
    | After changing the path, you should run `php artisan filament:assets`.
    |
    */

    'assets_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | This is the cache store Filament will use. You may use any of the stores
    | defined in `config/cache.php`.
    |
    | When set to `null`, the application's default cache store will be used.
    |
    */

    'cache_store' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache Path
    |--------------------------------------------------------------------------
    |
    | This is the directory that Filament will use to store cache files that
    | are used to optimize the registration of components.
    |
    | After changing the path, you should run `php artisan filament:cache-components`.
    |
    */

    'cache_path' => base_path('bootstrap/cache/filament'),

    /*
    |--------------------------------------------------------------------------
    | Livewire Loading Delay
    |--------------------------------------------------------------------------
    |
    | This sets the delay before loading indicators appear.
    |
    | Setting this to 'none' makes indicators appear immediately, which can be
    | desirable for high-latency connections. Setting it to 'default' applies
    | Livewire's standard 200ms delay.
    |
    */

    'livewire_loading_delay' => 'default',

    /*
    |--------------------------------------------------------------------------
    | File Generation
    |--------------------------------------------------------------------------
    |
    | Artisan commands that generate files can be configured here by setting
    | configuration flags that will impact their location or content.
    |
    | Often, this is useful to preserve file generation behavior from a
    | previous version of Filament, to ensure consistency between older and
    | newer generated files. These flags are often documented in the upgrade
    | guide for the version of Filament you are upgrading to.
    |
    */

    'file_generation' => [
        'flags' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Route Prefix
    |--------------------------------------------------------------------------
    |
    | This is the prefix used for the system routes that Filament registers,
    | such as the routes for downloading exports and failed import rows.
    |
    */

    'system_route_prefix' => 'filament',

];
