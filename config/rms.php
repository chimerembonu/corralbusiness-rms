<?php

/*
|--------------------------------------------------------------------------
| CorralBusiness – RMS product settings
|--------------------------------------------------------------------------
| Values that are the same for every tenant/branch live here. Anything a
| restaurant can change (tax rates, receipt header…) lives in the Settings
| module tables, not in this file.
*/

return [
    'product_name' => 'CorralBusiness – RMS',
    'company' => 'Corral Systems Limited',
    'version' => '0.1.0',

    // Branding (placeholder until the final Corral Systems identity is ready).
    'brand' => [
        'primary' => '#10b981',   // emerald
        'accent' => '#f59e0b',    // amber
        'dark' => '#020617',      // slate-950
        'logo' => 'brand/logo.svg',
        'logo_dark' => 'brand/logo-dark.svg',
        'mark' => 'brand/mark.svg',
        'favicon' => 'favicon.svg',
    ],

    // Money is stored as integers in the smallest unit (pesewas for GHS).
    'currency' => env('RMS_CURRENCY', 'GHS'),
    'currency_symbol' => env('RMS_CURRENCY_SYMBOL', 'GH₵'),
    'currency_minor_units' => 2, // 100 pesewas = 1 cedi

    // Which restaurant/branch THIS store server runs for (slug / branch code). Leave empty when only one exists.
    'store' => [
        'tenant' => env('RMS_STORE_TENANT'),
        'branch' => env('RMS_STORE_BRANCH'),
    ],

    // Filament panel paths.
    // Realtime (Reverb) as the BROWSER sees it. Empty = same address, port and scheme as the page the till opened.
    // The PHP side reaches the Reverb server through REVERB_HOST/PORT/SCHEME in config/broadcasting.php instead.
    'realtime' => [
        'key' => env('REVERB_APP_KEY'),
        'host' => env('REVERB_CLIENT_HOST'),
        'port' => env('REVERB_CLIENT_PORT'),
        'scheme' => env('REVERB_CLIENT_SCHEME'),
        'path' => env('REVERB_CLIENT_PATH'),
    ],

    'admin_path' => env('RMS_ADMIN_PATH', 'admin'),
    'hq_path' => env('RMS_HQ_PATH', 'hq'),

    // Minimum touch target in pixels for POS / KDS / clock-in screens (CLAUDE.md §3.9).
    'touch_target_px' => 48,

    // Kitchen display: ticket turns amber / red after this many minutes.
    'kitchen' => [
        'warn_after_minutes' => 5,
        'late_after_minutes' => 10,
    ],

    // Backups are locked (AES-256) with this password. Set by the installer; if empty, a password derived
    // from APP_KEY is used, so every backup is always locked. Show it with: php artisan rms:backup --show-password
    'backup' => [
        'password' => env('RMS_BACKUP_PASSWORD'),
    ],

    // PIN security (CLAUDE.md §3.14).
    'pin' => [
        'min_length' => 4,
        'max_length' => 6,
        'max_attempts' => 5,
        'lockout_seconds' => 60,
    ],
];
