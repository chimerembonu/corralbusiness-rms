<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Counter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Safe, gap-free number sequences even with six tills hitting the server at once. */
class CounterService
{
    public function __construct(private readonly TenantContext $context) {}

    public function next(string $key, ?string $scope = null, Branch|string|null $branch = null): int
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? $this->context->branchId());

        return DB::transaction(function () use ($key, $scope, $branchId) {
            $counter = Counter::query()
                ->where('branch_id', $branchId)
                ->where('key', $key)
                ->where('scope', $scope)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                $counter = Counter::create(['branch_id' => $branchId, 'key' => $key, 'scope' => $scope, 'value' => 0]);
            }

            $counter->value++;
            $counter->save();

            return $counter->value;
        });
    }
}
