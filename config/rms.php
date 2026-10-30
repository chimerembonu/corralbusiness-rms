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

    // Money is stored as integers in the smallest unit (pesewas for GHS).
    'currency' => env('RMS_CURRENCY', 'GHS'),
    'currency_symbol' => env('RMS_CURRENCY_SYMBOL', 'GH₵'),
    'currency_minor_units' => 2, // 100 pesewas = 1 cedi

    // Filament panel paths.
    'admin_path' => env('RMS_ADMIN_PATH', 'admin'),
    'hq_path' => env('RMS_HQ_PATH', 'hq'),

    // Minimum touch target in pixels for POS / KDS / clock-in screens (CLAUDE.md §3.9).
    'touch_target_px' => 48,

    // PIN security (CLAUDE.md §3.14).
    'pin' => [
        'min_length' => 4,
        'max_length' => 6,
        'max_attempts' => 5,
        'lockout_seconds' => 60,
    ],
];
