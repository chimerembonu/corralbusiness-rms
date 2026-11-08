<?php

namespace App\Modules\Printing\Models;

use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\PrintJobType;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends BusinessModel
{
    use MassPrunable;

    /** Finished print jobs are kept this long, then removed: they hold copies of receipts (E9.6 review F2). */
    public const KEEP_DAYS = 30;

    protected $fillable = ['branch_id', 'printer_id', 'type', 'status', 'document', 'reference_type', 'reference_id', 'attempts', 'last_error', 'printed_at', 'failed_at'];

    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'type' => PrintJobType::class,
            'status' => PrintJobStatus::class,
            'document' => 'array',
            'attempts' => 'integer',
            'printed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** Nightly (`model:prune`): printed or given-up jobs older than KEEP_DAYS, for every restaurant on this server. */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->whereIn('status', [PrintJobStatus::Printed->value, PrintJobStatus::Failed->value])
            ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function documentObject(): PrintDocument
    {
        return PrintDocument::fromArray($this->document ?? []);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', PrintJobStatus::Failed->value);
    }

    public function scopeWaitingBrowser(Builder $query): Builder
    {
        return $query->where('status', PrintJobStatus::WaitingBrowser->value);
    }
}
