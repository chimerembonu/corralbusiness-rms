<?php

namespace App\Modules\Settings\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Tax\TaxBase;
use App\Support\Tax\TaxComponentData;
use App\Support\Tax\TaxKind;
use Illuminate\Database\Eloquent\Builder;

/** A configurable tax component (VAT, NHIL, GETFund…) for a tenant or one branch. */
class TaxComponent extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'code', 'rate_basis_points', 'kind', 'base', 'sort_order', 'is_active'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['kind' => 'vat', 'base' => 'net', 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'rate_basis_points' => 'integer',
            'kind' => TaxKind::class,
            'base' => TaxBase::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function toData(): TaxComponentData
    {
        return new TaxComponentData(
            code: $this->code,
            name: $this->name,
            rateBasisPoints: $this->rate_basis_points,
            kind: $this->kind,
            base: $this->base,
            sortOrder: $this->sort_order,
        );
    }

    /** "15%" for lists. */
    public function ratePercent(): string
    {
        return $this->toData()->ratePercent().'%';
    }
}
