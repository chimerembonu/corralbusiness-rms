<?php

namespace App\Modules\Printing\Models;

use App\Modules\Printing\Enums\PrinterDriverType;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Printer extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'driver', 'address', 'paper_width', 'prints_receipts', 'prints_kitchen_tickets', 'has_cash_drawer', 'station_ids', 'is_active'];

    protected $attributes = ['driver' => 'browser', 'paper_width' => 80, 'prints_receipts' => false, 'prints_kitchen_tickets' => false, 'has_cash_drawer' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'driver' => PrinterDriverType::class,
            'paper_width' => 'integer',
            'prints_receipts' => 'boolean',
            'prints_kitchen_tickets' => 'boolean',
            'has_cash_drawer' => 'boolean',
            'station_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array{0: string, 1: int} host and port (default 9100) */
    public function networkAddress(): array
    {
        $address = trim((string) $this->address);
        if (str_contains($address, ':')) {
            [$host, $port] = explode(':', $address, 2);

            return [$host, (int) $port ?: 9100];
        }

        return [$address, 9100];
    }
}
