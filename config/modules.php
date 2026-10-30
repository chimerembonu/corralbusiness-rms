<?php

/*
|--------------------------------------------------------------------------
| Modules (CLAUDE.md §3.4 – modular monolith)
|--------------------------------------------------------------------------
| Each module lives in app/Modules/<Name>/ and is auto-loaded by
| App\Providers\ModuleServiceProvider (routes, migrations, views, lang,
| Livewire components, Filament resources, module service provider).
|
| 'core' modules can never be switched off. The others can be disabled
| globally here, and (from Phase 0.2) per tenant in the module_settings table.
*/

return [
    'path' => app_path('Modules'),
    'namespace' => 'App\\Modules',

    'modules' => [
        'Settings' => ['core' => true, 'enabled' => true],
        'Staff' => ['core' => true, 'enabled' => true],
        'Menu' => ['core' => true, 'enabled' => true],
        'Orders' => ['core' => true, 'enabled' => true],
        'Pos' => ['core' => true, 'enabled' => true],
        'Payments' => ['core' => true, 'enabled' => true],
        'Printing' => ['core' => true, 'enabled' => true],
        'Kitchen' => ['core' => false, 'enabled' => true],
        'Inventory' => ['core' => false, 'enabled' => true],
        'Customers' => ['core' => false, 'enabled' => true],
        'Reports' => ['core' => false, 'enabled' => true],
        'Sync' => ['core' => false, 'enabled' => false],          // Phase 6
        'OnlineOrdering' => ['core' => false, 'enabled' => false], // Phase 7
    ],
];
