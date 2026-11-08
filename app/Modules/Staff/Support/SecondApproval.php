<?php

namespace App\Modules\Staff\Support;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\SecondApprovalRequiredException;
use App\Modules\Staff\Models\User;

/**
 * Second-person approval (Complete Edition E9.4): when Till settings ask for it, a big refund or a day close
 * needs two different managers — the one who approved it and a second who confirms it.
 */
final class SecondApproval
{
    /** @throws SecondApprovalRequiredException */
    public static function assert(Permission $permission, User $first, ?User $second): void
    {
        if (! $second) {
            throw new SecondApprovalRequiredException($permission->value);
        }
        if ($second->is($first)) {
            throw new SecondApprovalRequiredException($permission->value, __('rms.pin.second_approval_same_person'));
        }
        if ($second->tenant_id !== $first->tenant_id || ! $second->is_active || ! $second->can($permission->value)) {
            throw new SecondApprovalRequiredException($permission->value, __('rms.pin.manager_required'));
        }
    }
}
