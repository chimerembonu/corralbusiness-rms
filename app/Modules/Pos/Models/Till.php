<?php

namespace App\Modules\Pos\Models;

use App\Modules\Printing\Models\Printer;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Till extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'code', 'receipt_printer_id', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function receiptPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'receipt_printer_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
