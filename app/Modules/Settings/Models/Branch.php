<?php

namespace App\Modules\Settings\Models;

use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BelongsToTenant;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One physical restaurant location. */
class Branch extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected bool $hasBranch = false;

    protected $fillable = ['tenant_id', 'name', 'code', 'address', 'phone', 'email', 'timezone', 'settings', 'is_active'];

    protected array $auditExcept = ['settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function timezone(): string
    {
        return $this->attributes['timezone'] ?? $this->tenant?->timezone ?? config('app.timezone');
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
