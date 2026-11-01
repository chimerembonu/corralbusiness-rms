<?php

namespace App\Modules\Kitchen\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends BusinessModel
{
    use Auditable;

    protected $table = 'kitchen_stations';

    protected $fillable = ['branch_id', 'name', 'code', 'color', 'sort_order', 'is_active', 'is_default', 'is_expo'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true, 'is_default' => false, 'is_expo' => false];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'is_default' => 'boolean', 'is_expo' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $station) {
            $station->code = strtoupper(trim($station->code));
        });
        // Only one default station per branch.
        static::saved(function (self $station) {
            if ($station->is_default) {
                static::query()->where('branch_id', $station->branch_id)->whereKeyNot($station->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'station_id');
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
