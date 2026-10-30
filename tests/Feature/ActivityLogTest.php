<?php

use App\Modules\Settings\Models\TaxComponent;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;

it('records changes to audited models with tenant and branch', function () {
    $branch = provisionTenant();
    $user = User::factory()->forBranch($branch)->create(['name' => 'Ama']);
    $this->actingAs($user);

    $user->update(['name' => 'Ama Serwaa']);

    $activity = Activity::query()->where('subject_type', User::class)->where('event', 'updated')->sole();

    expect($activity->tenant_id)->toBe($branch->tenant_id)
        ->and($activity->branch_id)->toBe($branch->id)
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Ama Serwaa')
        ->and($activity->attribute_changes['old']['name'])->toBe('Ama');
});

it('never writes PINs or passwords into the audit log', function () {
    $branch = provisionTenant();
    $user = User::factory()->forBranch($branch)->withPin('1234')->create();

    $user->update(['pin' => '5678', 'password' => 'new-secret']);

    foreach (Activity::query()->where('subject_type', User::class)->get() as $activity) {
        expect(json_encode($activity->attribute_changes))->not->toContain('pin', 'password')
            ->and(json_encode($activity->properties))->not->toContain('pin', 'password');
    }
});

it('logs tax rate changes', function () {
    provisionTenant();
    TaxComponent::where('code', 'VAT')->first()->update(['rate_basis_points' => 1250]);

    expect(Activity::query()->where('log_name', 'tax_component')->where('event', 'updated')->exists())->toBeTrue();
});
