<?php

namespace App\Modules\Settings\Models;

use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One restaurant business. Owns branches, users and all business data. */
class Tenant extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'country', 'currency', 'timezone', 'settings', 'is_active'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['country' => 'GH', 'currency' => 'GHS', 'timezone' => 'Africa/Accra', 'is_active' => true];

    protected array $auditExcept = ['settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function taxComponents(): HasMany
    {
        return $this->hasMany(TaxComponent::class);
    }

    public function moduleSettings(): HasMany
    {
        return $this->hasMany(ModuleSetting::class);
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
