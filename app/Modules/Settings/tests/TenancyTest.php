<?php

use App\Modules\Orders\Models\DiningTable;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\TaxComponent;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Settings\Services\SettingsService;
use App\Support\Tenancy\Tenancy;

it('gives every business row a version 7 UUID and tenant/branch stamps', function () {
    $branch = provisionTenant();

    $table = DiningTable::create(['name' => 'T1']);
    $setting = Setting::create(['key' => 'receipt.header', 'value' => ['v' => 'Akwaaba!']]);

    expect($table->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/')
        ->and($table->tenant_id)->toBe($branch->tenant_id)
        ->and($table->branch_id)->toBe($branch->id)
        ->and($setting->tenant_id)->toBe($branch->tenant_id)
        ->and($setting->branch_id)->toBeNull() // tenant-wide unless set explicitly
        ->and($branch->tenant->id)->toMatch('/^[0-9a-f-]{36}$/');
});

it('only shows rows of the current tenant', function () {
    $first = provisionTenant('First');
    $second = provisionTenant('Second');

    expect(TaxComponent::count())->toBe(3)
        ->and(TaxComponent::first()->tenant_id)->toBe($second->tenant_id);

    Tenancy::set($first->tenant, $first);
    expect(TaxComponent::first()->tenant_id)->toBe($first->tenant_id);

    Tenancy::forget();
    expect(TaxComponent::count())->toBe(6)
        ->and(Branch::count())->toBe(2);
});

it('soft deletes business rows', function () {
    provisionTenant();
    $component = TaxComponent::where('code', 'VAT')->first();

    $component->delete();

    expect(TaxComponent::where('code', 'VAT')->exists())->toBeFalse()
        ->and(TaxComponent::withTrashed()->where('code', 'VAT')->exists())->toBeTrue();
});

it('lets a branch value override a tenant-wide setting', function () {
    $branch = provisionTenant();
    $service = app(SettingsService::class);

    $service->set('receipt.footer', 'Thank you', tenantWide: true);
    expect($service->get('receipt.footer'))->toBe('Thank you');

    $service->set('receipt.footer', 'Medaase', $branch);
    expect($service->get('receipt.footer'))->toBe('Medaase')
        ->and($service->get('missing', 'default'))->toBe('default');
});

it('tracks module switches per tenant', function () {
    provisionTenant();
    $service = app(SettingsService::class);

    expect($service->moduleEnabled('Kitchen'))->toBeTrue()
        ->and($service->moduleEnabled('Sync'))->toBeFalse();

    $service->setModuleEnabled('Kitchen', false);
    expect($service->moduleEnabled('Kitchen'))->toBeFalse()
        ->and($service->moduleEnabled('Orders'))->toBeTrue(); // core: cannot be switched off
});

it('provisions a tenant with a main branch and default taxes', function () {
    $branch = provisionTenant('Auntie Muni Waakye');

    expect($branch->code)->toBe('MAIN')
        ->and(Tenant::first()->slug)->toBe('auntie-muni-waakye')
        ->and(TaxComponent::count())->toBe(3);
});
