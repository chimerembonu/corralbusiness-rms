<?php

namespace App\Support\Audit;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Put `use Auditable;` on a model and every create/update/delete is written to
 * the activity log with only the changed fields (CLAUDE.md §3.11).
 * Override auditLogName() or set $auditExcept to tune.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $except = property_exists($this, 'auditExcept') ? $this->auditExcept : [];

        return LogOptions::defaults()
            ->useLogName($this->auditLogName())
            ->logFillable()
            ->logExcept(array_merge(['updated_at', 'created_at', 'deleted_at'], $except))
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function auditLogName(): string
    {
        return str(class_basename($this))->snake()->toString();
    }
}
