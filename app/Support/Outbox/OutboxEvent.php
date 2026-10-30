<?php

namespace App\Support\Outbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only outbox row (CLAUDE.md §3.3). Never updated except to set synced_at.
 */
class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox';

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'branch_id', 'event_type', 'payload', 'created_at', 'synced_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('synced_at');
    }
}
