<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * $table->businessColumns()  → id (uuid), tenant_id, branch_id, timestamps, soft deletes.
 * $table->businessColumns(branch: false) → same without branch_id (tenant-wide tables).
 * $table->businessColumns(branchNullable: true) → branch_id may be empty (tenant-wide OR per-branch rows).
 */
class BlueprintMacros
{
    public static function register(): void
    {
        Blueprint::macro('businessColumns', function (bool $branch = true, bool $branchNullable = false) {
            /** @var Blueprint $this */
            $this->uuid('id')->primary();
            $this->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            if ($branch) {
                $column = $this->foreignUuid('branch_id');
                if ($branchNullable) {
                    $column->nullable();
                }
                $column->constrained('branches')->cascadeOnDelete();
            }

            $this->timestamps();
            $this->softDeletes();

            return $this;
        });
    }
}
