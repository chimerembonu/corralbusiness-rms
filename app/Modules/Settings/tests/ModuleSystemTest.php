<?php

use App\Support\Modules;

it('declares every module from CLAUDE.md §3.4', function () {
    expect(Modules::all())->toContain(
        'Menu', 'Orders', 'Pos', 'Kitchen', 'Payments', 'Inventory', 'Staff',
        'Customers', 'Reports', 'Printing', 'Sync', 'OnlineOrdering', 'Settings',
    );
});

it('never switches off a core module', function () {
    config(['modules.modules.Settings.enabled' => false]);

    expect(Modules::isEnabled('Settings'))->toBeTrue();
});

it('respects the enabled flag for optional modules', function () {
    expect(Modules::isEnabled('Sync'))->toBeFalse();

    config(['modules.modules.Sync.enabled' => true]);

    expect(Modules::isEnabled('Sync'))->toBeTrue();
});

it('resolves module paths and namespaces', function () {
    expect(Modules::path('Menu'))->toBe(app_path('Modules/Menu'))
        ->and(Modules::namespace('Menu', 'Models\\Item'))->toBe('App\\Modules\\Menu\\Models\\Item')
        ->and(Modules::viewNamespace('OnlineOrdering'))->toBe('online-ordering');
});

it('runs module tests with the Laravel test case', function () {
    provisionTenant();
    $this->get('/')->assertOk();
});
