<?php

namespace App\Modules\Kitchen\Models;

use App\Modules\Kitchen\Enums\TicketStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends BusinessModel
{
    protected $table = 'kitchen_tickets';

    protected $fillable = ['branch_id', 'order_id', 'station_id', 'order_number', 'order_type', 'table_name', 'server_name', 'status', 'is_training', 'notes', 'sent_at'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'is_training' => 'boolean',
            'order_number' => 'integer',
            'status' => TicketStatus::class,
            'sent_at' => 'datetime',
            'started_at' => 'datetime',
            'done_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TicketItem::class, 'ticket_id')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [TicketStatus::New->value, TicketStatus::InProgress->value]);
    }

    public function displayNumber(): string
    {
        return str_pad((string) $this->order_number, 4, '0', STR_PAD_LEFT);
    }

    public function ageSeconds(): int
    {
        return (int) $this->sent_at->diffInSeconds($this->done_at ?? now());
    }

    /** green < 5 min, amber < 10 min, red after (thresholds from config/rms.php). */
    public function ageBucket(): string
    {
        $minutes = $this->ageSeconds() / 60;

        return match (true) {
            $minutes >= (int) config('rms.kitchen.late_after_minutes', 10) => 'late',
            $minutes >= (int) config('rms.kitchen.warn_after_minutes', 5) => 'warn',
            default => 'fresh',
        };
    }

    /** @return array<string, mixed> for broadcasting */
    public function toBroadcast(): array
    {
        return ['id' => $this->id, 'order_number' => $this->order_number, 'status' => $this->status->value, 'station_id' => $this->station_id];
    }
}
