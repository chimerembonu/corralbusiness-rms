<?php

/*
|--------------------------------------------------------------------------
| Payment providers (Complete Edition E2)
|--------------------------------------------------------------------------
| To add a provider: write one class implementing PaymentGateway (see
| docs/ADDING-A-PAYMENT-PROVIDER.md), add it here, add its fields to
| config/connections.php under the same 'connection' name, and add its name
| to the payments language file. It then appears on Settings → Connections and
| Settings → Payment providers, and can be chosen as the default — no other
| code changes.
*/

use App\Modules\Payments\Gateways\Providers\DemoGateway;
use App\Modules\Payments\Gateways\Providers\PaystackGateway;
use App\Modules\Payments\Gateways\Providers\PaySwitchGateway;

return [
    'gateways' => [
        'paystack' => [
            'class' => PaystackGateway::class,
            'connection' => 'paystack',
            // The details asked for on Settings → Connections. 'secret' fields are stored locked.
            'fields' => [
                'mode' => ['type' => 'select', 'options' => ['test', 'live'], 'required' => true],
                'public_key' => ['type' => 'text', 'required' => true],
                'secret_key' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
        ],
        'payswitch' => [
            'class' => PaySwitchGateway::class,
            'connection' => 'payswitch',
            'fields' => [
                'mode' => ['type' => 'select', 'options' => ['test', 'live'], 'required' => true],
                'merchant_id' => ['type' => 'text', 'required' => true],
                'api_user' => ['type' => 'text', 'required' => true],
                'api_key' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
        ],
        // For trials and staff training: only ever works on training-mode orders, never takes real money.
        'demo' => [
            'class' => DemoGateway::class,
            'connection' => 'demo_payments',
            'fields' => [
                'mode' => ['type' => 'select', 'options' => ['demo'], 'required' => true],
            ],
        ],
    ],

    // What each kind of payment needs from a provider. The owner picks a default provider per purpose;
    // if it is not ready, the first ready provider that can do it is used.
    'purposes' => [
        'till_momo' => 'momo_prompt',
        'online' => 'hosted_checkout',
    ],

    'momo' => [
        'expire_after_minutes' => (int) env('RMS_MOMO_EXPIRE_MINUTES', 3),
        'keep_checking_minutes' => 30,   // a "cancelled" or "expired" prompt is still checked this long, in case the money arrives late
        'till_poll_seconds' => 4,
    ],

    'http' => ['connect_timeout' => 5, 'timeout' => 15],

    'paystack' => [
        'base_url' => 'https://api.paystack.co',
        // Paystack's codes for Ghana networks (checked against paystack.com/docs on 5 Nov 2026; confirm with a test prompt).
        'networks' => ['mtn' => 'mtn', 'telecel' => 'vod', 'at' => 'atl'],
        // Paystack needs an email; customers at a till rarely give one, so this address is used instead.
        'fallback_email_domain' => env('RMS_PAYSTACK_EMAIL_DOMAIN', 'customers.corralbusiness.app'),
    ],

    'payswitch' => [
        'base_url' => ['test' => 'https://test.theteller.net', 'live' => 'https://prod.theteller.net'],
        'checkout_url' => ['test' => 'https://checkout-test.theteller.net', 'live' => 'https://checkout.theteller.net'],
        // theteller "r-switch" codes. Confirm against the PaySwitch test account before going live.
        'networks' => ['mtn' => 'MTN', 'telecel' => 'VDF', 'at' => 'ATL'],
    ],
];
