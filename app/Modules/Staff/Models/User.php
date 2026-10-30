<?php

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Audit\Auditable;
use App\Support\Models\BelongsToTenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Traits\HasRoles;

/** A staff member or owner. Email+password for the back office, PIN for POS screens. */
class User extends Authenticatable implements FilamentUser
{
    use Auditable;
    use BelongsToTenant;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'branch_id', 'name', 'email', 'phone', 'password', 'pin', 'is_active'];

    protected $hidden = ['password', 'pin', 'pin_lookup', 'remember_token'];

    protected array $auditExcept = ['password', 'pin', 'pin_lookup', 'remember_token', 'last_login_at'];

    /** Plain PIN waiting to be turned into pin_lookup once tenant_id is known. */
    private ?string $pendingPin = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $syncLookup = function (User $user) {
            if ($user->pendingPin !== null) {
                $user->pin_lookup = PinHasher::lookup($user->tenant_id, $user->pendingPin);
                $user->pendingPin = null;
            }
        };

        static::creating($syncLookup);
        static::updating($syncLookup);
    }

    /** Setting ->pin = '1234' hashes it and prepares the lookup key. */
    public function setPinAttribute(?string $pin): void
    {
        if ($pin === null || $pin === '') {
            $this->attributes['pin'] = null;
            $this->attributes['pin_lookup'] = null;
            $this->pendingPin = null;

            return;
        }

        $pin = PinHasher::validate($pin);
        $this->attributes['pin'] = Hash::make($pin);
        $this->pendingPin = $pin;
    }

    public function hasPin(): bool
    {
        return $this->pin !== null;
    }

    public function verifyPin(string $pin): bool
    {
        return $this->pin !== null && Hash::check($pin, $this->pin);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'hq' => $this->can(Permission::HqAccess->value),
            default => $this->can(Permission::AdminAccess->value),
        };
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
