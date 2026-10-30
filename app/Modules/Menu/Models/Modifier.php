<?php

namespace App\Modules\Menu\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One choice inside a modifier group, e.g. "Chicken +GH₵ 15". */
class Modifier extends BusinessModel
{
    use Auditable;

    protected $table = 'menu_modifiers';

    /** Tenant-wide table: branch_id is only set when a row is specific to one branch. */
    protected bool $hasBranch = false;

    protected $fillable = ['branch_id', 'modifier_group_id', 'name', 'price', 'is_default', 'is_sold_out', 'sort_order', 'is_active'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['price' => 0, 'is_default' => false, 'is_sold_out' => false, 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'is_default' => 'boolean',
            'is_sold_out' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
