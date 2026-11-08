<?php

namespace App\Modules\Staff\Services;

use App\Modules\Settings\Models\Tenant;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Audit\SecurityLog;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PIN login for POS / KDS / clock-in screens (CLAUDE.md §3.14):
 * 5 wrong PINs from the same till → locked for 60 seconds (configurable in config/rms.php), and at most
 * 10 wrong PINs a minute from one device whichever till it claims to be (security review N3).
 */
class PinAuthenticator
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  string  $throttleKey  Identifies the till, e.g. the request IP or device id.
     *
     * @throws PinLockedException|InvalidPinException
     */
    public function attempt(#[\SensitiveParameter] string $pin, string $throttleKey, Tenant|string|null $tenant = null): User
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : ($tenant ?? $this->context->tenantId());
        $limiterKey = $this->limiterKey($tenantId, $throttleKey);
        $maxAttempts = (int) config('rms.pin.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            throw new PinLockedException(RateLimiter::availableIn($limiterKey));
        }
        $deviceKey = $this->deviceKey($tenantId);
        $deviceMax = (int) config('rms.pin.max_attempts_per_device', 10);
        if ($deviceKey && RateLimiter::tooManyAttempts($deviceKey, $deviceMax)) {
            throw new PinLockedException(RateLimiter::availableIn($deviceKey));
        }

        $user = $this->findByPin($pin, $tenantId);

        if ($user === null || ! $user->is_active || ! $user->verifyPin($pin)) {
            RateLimiter::hit($limiterKey, (int) config('rms.pin.lockout_seconds', 60));
            if ($deviceKey) {
                // Not cleared by a correct PIN: mixing in your own PIN must not reset the count of guesses.
                RateLimiter::hit($deviceKey, (int) config('rms.pin.lockout_seconds', 60));
            }
            $left = max(0, min($maxAttempts - RateLimiter::attempts($limiterKey), $deviceKey ? $deviceMax - RateLimiter::attempts($deviceKey) : PHP_INT_MAX));
            // Record the failure (never the PIN). A deactivated person's correct PIN is flagged separately.
            $inactive = $user !== null && ! $user->is_active && $user->verifyPin($pin) ? $user : null;
            SecurityLog::pinFailed($throttleKey, $tenantId, $left, $inactive);

            if ($left === 0) {
                SecurityLog::pinLockedOut($throttleKey, $tenantId);

                throw new PinLockedException(max(RateLimiter::availableIn($limiterKey), $deviceKey ? RateLimiter::availableIn($deviceKey) : 0));
            }

            throw new InvalidPinException($left);
        }

        RateLimiter::clear($limiterKey);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $user;
    }

    /**
     * Manager override: the PIN must belong to someone who holds the given permission
     * (e.g. Permission::OrdersVoid) and, when a branch is given, who works at that branch or is
     * branch-less (the owner). Returns the approving user for the audit trail.
     *
     * @throws PinLockedException|InvalidPinException|ManagerPinRequiredException
     */
    public function authorize(#[\SensitiveParameter] string $pin, Permission|string $permission, string $throttleKey, Tenant|string|null $tenant = null, ?string $branchId = null): User
    {
        $permission = $permission instanceof Permission ? $permission->value : $permission;
        $user = $this->attempt($pin, $throttleKey, $tenant);

        if (! $user->can($permission)) {
            SecurityLog::approvalRefused($user, $permission, $throttleKey);

            throw new ManagerPinRequiredException($permission);
        }

        if ($branchId !== null && $user->branch_id !== null && $user->branch_id !== $branchId) {
            throw new ManagerPinRequiredException($permission, __('rms.pin.other_branch')); // security review L4
        }

        return $user;
    }

    public function isLocked(string $throttleKey, Tenant|string|null $tenant = null): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : ($tenant ?? $this->context->tenantId());

        $deviceKey = $this->deviceKey($tenantId);

        return RateLimiter::tooManyAttempts($this->limiterKey($tenantId, $throttleKey), (int) config('rms.pin.max_attempts', 5))
            || ($deviceKey && RateLimiter::tooManyAttempts($deviceKey, (int) config('rms.pin.max_attempts_per_device', 10)));
    }

    public function secondsUntilUnlocked(string $throttleKey, Tenant|string|null $tenant = null): int
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : ($tenant ?? $this->context->tenantId());

        $deviceKey = $this->deviceKey($tenantId);

        return max(RateLimiter::availableIn($this->limiterKey($tenantId, $throttleKey)), $deviceKey ? RateLimiter::availableIn($deviceKey) : 0);
    }

    private function findByPin(#[\SensitiveParameter] string $pin, ?string $tenantId): ?User
    {
        return User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('pin_lookup', PinHasher::lookup($tenantId, $pin))
            ->first();
    }

    /** The device's own counter: its network address (none outside a web request, e.g. commands). */
    private function deviceKey(?string $tenantId): ?string
    {
        $ip = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip();

        return $ip ? 'pin-device:'.($tenantId ?? 'global').':'.$ip : null;
    }

    private function limiterKey(?string $tenantId, string $throttleKey): string
    {
        return 'pin:'.($tenantId ?? 'global').':'.$throttleKey;
    }
}
