<?php

namespace App\Modules\Kitchen\Models;

use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketItem extends BusinessModel
{
    protected $table = 'kitchen_ticket_items';

    protected $fillable = ['branch_id', 'ticket_id', 'order_item_id', 'name', 'quantity', 'modifiers', 'notes', 'seat', 'is_voided', 'sort_order'];

    protected $attributes = ['quantity' => 1, 'is_voided' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'modifiers' => 'array', 'seat' => 'integer', 'is_voided' => 'boolean', 'sort_order' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
