<?php

/*
|--------------------------------------------------------------------------
| Features (Complete Edition, PLAN.md "Two tracks")
|--------------------------------------------------------------------------
| Every optional feature of the Complete Edition. All are OFF until switched on
| in Back office → Settings → Features. A feature can only be switched on when it
| is built ('available'), the licence covers it ('tier'), and every connection it
| 'needs' is set up and has passed its test (Settings → Connections).
|
| tier: starter < pro < business. 'stage' is the build stage that delivers it.
*/

return [
    'features' => [
        'momo_prompt' => ['group' => 'payments', 'needs' => ['payment_provider'], 'tier' => 'pro', 'stage' => 'E2', 'available' => true],
        'online_payments' => ['group' => 'payments', 'needs' => ['payment_provider', 'online_service'], 'tier' => 'pro', 'stage' => 'E2', 'available' => false],
        'online_ordering' => ['group' => 'online', 'needs' => ['online_service', 'payment_provider'], 'tier' => 'business', 'stage' => 'E4', 'available' => false],
        'remote_dashboard' => ['group' => 'online', 'needs' => ['online_service'], 'tier' => 'pro', 'stage' => 'E3', 'available' => false],
        'offsite_backups' => ['group' => 'online', 'needs' => ['online_service'], 'tier' => 'pro', 'stage' => 'E3', 'available' => false],
        'sms' => ['group' => 'messages', 'needs' => ['sms'], 'tier' => 'pro', 'stage' => 'E4', 'available' => false],
        'whatsapp' => ['group' => 'messages', 'needs' => ['whatsapp'], 'tier' => 'business', 'stage' => 'E4', 'available' => false],
        'recipes' => ['group' => 'kitchen', 'needs' => [], 'tier' => 'pro', 'stage' => 'E5', 'available' => true],
        'accounting_export' => ['group' => 'office', 'needs' => [], 'tier' => 'pro', 'stage' => 'E5', 'available' => false],
        'payroll' => ['group' => 'office', 'needs' => [], 'tier' => 'business', 'stage' => 'E6', 'available' => false],
        'reservations' => ['group' => 'guests', 'needs' => [], 'tier' => 'pro', 'stage' => 'E7', 'available' => false],
        'loyalty' => ['group' => 'guests', 'needs' => [], 'tier' => 'pro', 'stage' => 'E7', 'available' => false],
        'gift_vouchers' => ['group' => 'guests', 'needs' => [], 'tier' => 'pro', 'stage' => 'E7', 'available' => false],
        'evat' => ['group' => 'tax', 'needs' => ['evat'], 'tier' => 'starter', 'stage' => 'E8', 'available' => false],
        'delivery_apps' => ['group' => 'online', 'needs' => [], 'tier' => 'pro', 'stage' => 'E8', 'available' => false],
        'customer_display' => ['group' => 'extras', 'needs' => [], 'tier' => 'pro', 'stage' => 'E8', 'available' => false],
        'barcode_scanner' => ['group' => 'extras', 'needs' => [], 'tier' => 'starter', 'stage' => 'E8', 'available' => false],
    ],

    // A "need" is met by any one of these connections being set up and tested.
    'needs' => [
        'payment_provider' => '@gateways', // any provider in config/payments.php
        'online_service' => ['corral_cloud', 'own_server'],
        'sms' => ['sms'],
        'whatsapp' => ['whatsapp'],
        'evat' => ['evat'],
    ],

    'tiers' => ['starter' => 1, 'pro' => 2, 'business' => 3],
];
