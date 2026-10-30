<?php

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base class for every business table (CLAUDE.md §3.2–3.3):
 * UUID v7 primary key, tenant_id + branch_id, created/updated/deleted timestamps.
 *
 * Create the columns in a migration with  $table->businessColumns();
 */
abstract class BusinessModel extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    /** Tables scoped to a tenant but not to a branch set this to false. */
    protected bool $hasBranch = true;

    protected $guarded = ['id', 'tenant_id'];
}
