<?php

return [
    'navigation_group' => 'Settings',
    'till' => [
        'title' => 'Till settings',
        'subheading' => 'Rules every till follows at :branch.',
        'service_charge' => 'Service charge',
        'service_charge_percent' => 'Service charge rate',
        'service_charge_help' => '0 switches it off. It is added after tax and shown as its own line on the receipt.',
        'service_charge_types' => 'Charge it on',
        'discounts' => 'Discounts and voids',
        'discount_threshold' => 'Manager PIN needed for discounts above',
        'discount_threshold_help' => 'A percentage of the amount being discounted. Voids and refunds always need a manager PIN.',
        'discount_reasons' => 'Discount reasons (shown as buttons on the till)',
        'void_reasons' => 'Void reasons',
        'add_reason' => 'Type a reason and press Enter',
        'other' => 'Other',
        'tips_enabled' => 'Allow tips at payment',
        'courses' => 'Course names',
        'courses_help' => 'First course goes to the kitchen straight away; later courses wait until the waiter taps “Fire”.',
        'delivery_fee' => 'Default delivery fee',
        'delivery_fee_help' => 'Suggested on every new delivery order; the cashier can change it per order. Added after tax.',
        'save' => 'Save settings',
        'saved' => 'Till settings saved.',
    ],
];
