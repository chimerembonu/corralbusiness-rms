<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\TableShape;
use App\Modules\Orders\Enums\TableStatus;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningTable extends BusinessModel
{
    use Auditable;

    /** Size of the floor plan grid (cells). Positions and sizes are in cells. */
    public const COLS = 20;

    public const ROWS = 12;

    protected $fillable = ['branch_id', 'name', 'section_id', 'seats', 'shape', 'pos_x', 'pos_y', 'width', 'height', 'sort_order', 'is_active'];

    protected $attributes = ['seats' => 4, 'shape' => 'square', 'width' => 2, 'height' => 2, 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'seats' => 'integer', 'shape' => TableShape::class, 'pos_x' => 'integer', 'pos_y' => 'integer',
            'width' => 'integer', 'height' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(FloorSection::class, 'section_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    /** The order currently open on this table, if any. */
    public function currentOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'table_id')
            ->whereIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value])
            ->latestOfMany('opened_at');
    }

    /** The table this one is joined onto (its order lives there). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Tables joined onto this one. */
    public function mergedTables(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isOccupied(): bool
    {
        return $this->relationLoaded('currentOrder') ? $this->currentOrder !== null : $this->currentOrder()->exists();
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    public function isPlaced(): bool
    {
        return $this->pos_x !== null && $this->pos_y !== null;
    }

    /** Free / seated / ordered / merged, from the open order (loads it when needed). */
    public function status(): TableStatus
    {
        if ($this->isMerged()) {
            return TableStatus::Merged;
        }

        $order = $this->relationLoaded('currentOrder') ? $this->currentOrder : $this->currentOrder()->first();

        return match (true) {
            $order === null => TableStatus::Free,
            $order->status === OrderStatus::Sent => TableStatus::Ordered,
            default => TableStatus::Seated,
        };
    }

    /** Seats of this table plus every table joined onto it. */
    public function totalSeats(): int
    {
        $joined = $this->relationLoaded('mergedTables') ? $this->mergedTables : $this->mergedTables()->get();

        return $this->seats + (int) $joined->sum('seats');
    }
}
