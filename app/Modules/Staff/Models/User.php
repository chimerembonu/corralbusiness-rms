<?php

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Audit\Auditable;
use App\Support\Models\BelongsToTenant;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/** A staff member or owner. Email+password for the back office, PIN for POS screens. */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use Auditable;
    use BelongsToTenant;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'branch_id', 'name', 'email', 'phone', 'password', 'pin', 'is_active', 'job_title', 'started_on', 'photo_path', 'notes'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['is_active' => true, 'app_authentication_secret' => null, 'app_authentication_recovery_codes' => null];

    protected $hidden = ['password', 'pin', 'pin_lookup', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected array $auditExcept = ['password', 'pin', 'pin_lookup', 'remember_token', 'last_login_at', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    /** Plain PIN waiting to be turned into pin_lookup once tenant_id is known. */
    private ?string $pendingPin = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'started_on' => 'date',
            // Two-step sign-in (E9.2): the authenticator app's secret and the recovery codes, locked with the app key.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    /** The name shown in the authenticator app, e.g. "Efua (efua@example.com)". */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email ?? $this->name;
    }

    /** @return array<int, string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param  array<int, string>|null  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasTwoStepSignIn(): bool
    {
        return filled($this->app_authentication_secret);
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

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function rosterShifts(): HasMany
    {
        return $this->hasMany(RosterShift::class);
    }

    /** The entry still open (clocked in, not yet out), if any. */
    public function openTimeEntry(): ?TimeEntry
    {
        return $this->timeEntries()->open()->latest('clocked_in_at')->first();
    }

    /** "Cashier" / "Manager, Cashier" from the assigned roles. */
    public function rolesLabel(): string
    {
        return $this->roles->map(fn ($r) => Role::tryFrom($r->name)?->label() ?? $r->name)->join(', ');
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
