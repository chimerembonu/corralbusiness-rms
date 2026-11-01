<?php

namespace App\Modules\Customers\Models;

use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends BusinessModel
{
    protected bool $hasBranch = false;

    protected $fillable = ['customer_id', 'label', 'address', 'notes', 'is_default'];

    protected $attributes = ['is_default' => false];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function label(): string
    {
        return ($this->label ? $this->label.': ' : '').$this->address;
    }
}
