<?php

namespace App\Modules\Kitchen\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Items in this category / this item go to station X" (tenant-wide, or for one branch). */
class Route extends BusinessModel
{
    use Auditable;

    public const CATEGORY = 'category';

    public const ITEM = 'item';

    protected $table = 'kitchen_routes';

    protected bool $hasBranch = false;

    protected $fillable = ['branch_id', 'routable_type', 'routable_id', 'station_id'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }
}
