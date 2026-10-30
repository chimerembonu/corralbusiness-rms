<?php

namespace App\Modules\Menu\Models;

use App\Modules\Menu\Support\HasAvailability;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends BusinessModel
{
    use Auditable;
    use HasAvailability;

    protected $table = 'menu_categories';

    /** Tenant-wide table: branch_id is only set when a row is specific to one branch. */
    protected bool $hasBranch = false;

    protected $fillable = ['branch_id', 'name', 'description', 'color', 'image_path', 'availability', 'sort_order', 'is_active'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'availability' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id')->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
