<?php

namespace App\Modules\Inventory\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shared by every branch (branch_id stays empty). */
class Supplier extends BusinessModel
{
    use Auditable;

    protected bool $hasBranch = false;

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'address', 'payment_terms', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
