<?php

return [
    'product_name' => 'CorralBusiness – RMS',
    'tagline' => 'Restaurant Management System',
    'company' => 'Corral Systems Limited',
    'placeholder' => [
        'title' => 'Store server is up and running',
        'subtitle' => 'The point-of-sale, kitchen display and back office will appear here as they are built.',
        'database' => 'Database',
        'connected' => 'Connected',
        'not_connected' => 'Not connected',
        'timezone' => 'Timezone',
        'currency' => 'Currency',
        'version' => 'Version',
        'modules' => 'Modules loaded',
        'admin_link' => 'Back office',
        'pos_link' => 'Point of sale',
        'kitchen_link' => 'Kitchen display',
        'coming_soon' => 'coming soon',
    ],
    'roles' => [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'waiter' => 'Waiter',
        'kitchen' => 'Kitchen',
        'rider' => 'Rider',
        'inventory' => 'Inventory',
        'accountant' => 'Accountant',
    ],
    'pin' => [
        'invalid' => 'Wrong PIN. :left attempts left.',
        'locked' => 'Too many wrong PINs. Try again in :seconds seconds.',
        'manager_required' => 'A manager PIN is required for this action.',
        'invalid_format' => 'PIN must be :min to :max digits.',
    ],
    'tax' => [
        'kind' => ['vat' => 'VAT', 'levy' => 'Levy'],
        'base' => ['net' => 'On the net price', 'net_plus_levies' => 'On net price plus levies'],
    ],
];
