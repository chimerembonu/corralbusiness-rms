<?php

/*
|--------------------------------------------------------------------------
| Licence (Complete Edition E1.2)
|--------------------------------------------------------------------------
| A licence code is issued by Corral Systems per restaurant: plan (starter / pro / business, see
| config/features.php "tiers" and each feature's "tier"), number of branches and end date. It is checked
| on the store server WITHOUT internet, using the public keys below. The matching SECRET signing key never
| leaves Corral Systems (see `php artisan rms:licence-keys`).
|
| These keys are deliberately not read from .env: a restaurant's server must not be able to swap in a key
| of its own. To retire a key, add the new one, issue fresh codes, then remove the old one.
*/

return [
    // key id => base64 Ed25519 public key
    'public_keys' => [
        'corral-2026' => 'Sx3w4WA5M3gWQC+mwRvnZtNaQv61uqHe1Oqzrba4O6g=',
    ],

    // Days after the end date where everything still works (with a warning).
    'grace_days' => 14,

    // Reports-only starts at this hour (24h, restaurant time) the morning after the grace period:
    // a quiet hour, so a till is never stopped in the middle of service.
    'lock_hour' => 4,

    // Start warning the owner and managers this many days before the end date.
    'warn_days' => 30,

    // Back-office pages still open in reports-only mode (route names; * = anything).
    'read_only_pages' => [
        'filament.*.pages.dashboard',
        'filament.admin.pages.licence',
        'filament.admin.pages.reports.*',
        'filament.admin.pages.staff-sales',
        'filament.admin.pages.timesheets',
        'filament.admin.pages.backups',
        'filament.admin.pages.security-log',
        'filament.admin.resources.day-closes.*',
        'filament.admin.resources.cashier-sessions.*',
    ],

    // Corral Systems office only: the file holding the secret signing key, used by rms:licence-issue.
    // Never set on a restaurant's server.
    'signing_key_path' => env('RMS_LICENCE_SIGNING_KEY'),
];
