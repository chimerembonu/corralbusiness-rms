<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'number', 'supplier_id', 'status', 'expected_on', 'ordered_at', 'received_at', 'created_by_id', 'received_by_id', 'reference', 'total', 'notes'];

    protected $attributes = ['status' => 'draft', 'total' => 0];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'expected_on' => 'date',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'total' => MoneyCast::class,
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [PurchaseStatus::Draft->value, PurchaseStatus::Ordered->value]);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function displayNumber(): string
    {
        return 'PO'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }
}
