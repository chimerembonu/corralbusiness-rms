<?php

use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Facades\Broadcast;

// Kitchen screens of one branch. Anyone who may view the kitchen and belongs to the branch's restaurant.
Broadcast::channel('kitchen.{branchId}', function ($user, string $branchId) {
    if (! $user->can(Permission::KitchenView->value)) {
        return false;
    }

    return Branch::query()->withoutGlobalScope(TenantScope::class)
        ->where('id', $branchId)->where('tenant_id', $user->tenant_id)->exists();
});
