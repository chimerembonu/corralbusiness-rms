<?php

/*
|--------------------------------------------------------------------------
| Connections (Complete Edition): outside services filled in at installation
|--------------------------------------------------------------------------
| Back office → Settings → Connections (owner only). Fields marked 'secret' are
| stored encrypted and never shown again in full. 'tester' checks the details
| against the real service; until a tester is built the connection cannot pass.
*/

return [
    'connections' => [
        // Payment providers are declared in config/payments.php ('fields') and added here automatically.
        'corral_cloud' => [
            'stage' => 'E3',
            'fields' => [
                'activation_code' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
            'tester' => null,
        ],
        'own_server' => [
            'stage' => 'E3',
            'fields' => [
                'url' => ['type' => 'url', 'required' => true],
                'token' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
            'tester' => null,
        ],
        'sms' => [
            'stage' => 'E4',
            'fields' => [
                'sender_id' => ['type' => 'text', 'required' => true, 'max' => 11],
                'api_url' => ['type' => 'url', 'required' => true],
                'api_key' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
            'tester' => null,
        ],
        'whatsapp' => [
            'stage' => 'E4',
            'fields' => [
                'phone_number_id' => ['type' => 'text', 'required' => true],
                'access_token' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
            'tester' => null,
        ],
        'evat' => [
            'stage' => 'E8',
            'fields' => [
                'mode' => ['type' => 'select', 'options' => ['test', 'live'], 'required' => true],
                'tin' => ['type' => 'text', 'required' => true],
                'api_key' => ['type' => 'text', 'secret' => true, 'required' => true],
            ],
            'tester' => null,
        ],
    ],
];
