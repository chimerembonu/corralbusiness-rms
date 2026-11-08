<?php

use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Facades\Broadcast;

// Kitchen screens of one branch (E3.1, security review D2): staff of THAT branch who may view the kitchen.
// People of the whole restaurant (no home branch, e.g. the owner) may watch any branch of their own restaurant.
Broadcast::channel('kitchen.{branchId}', function ($user, string $branchId) {
    if (! $user->can(Permission::KitchenView->value)) {
        return false;
    }
    if ($user->branch_id !== null && $user->branch_id !== $branchId) {
        return false;
    }

    return Branch::query()->withoutGlobalScope(TenantScope::class)
        ->where('id', $branchId)->where('tenant_id', $user->tenant_id)->exists();
});
