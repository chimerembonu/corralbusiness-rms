<?php

namespace App\Support\Audit;

use App\Modules\Staff\Models\User;
use App\Support\Tenancy\StoreTenantResolver;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Failed sign-ins and PIN attempts (compliance assessment, 4 Nov 2026). Written to the activity log under
 * log name "security" and shown on Back office → Settings → Security log. Never records a PIN or password.
 */
final class SecurityLog
{
    public const LOG = 'security';

    /** Wrong PIN at a till, the clock-in screen or the manager keypad. */
    public static function pinFailed(string $till, ?string $tenantId, int $attemptsLeft, ?User $matchedInactive = null): void
    {
        self::write($matchedInactive ? 'pin_inactive_user' : 'pin_failed', $tenantId, $matchedInactive, [
            'till' => $till, 'attempts_left' => $attemptsLeft,
        ]);
    }

    /** Five wrong PINs in a row: the till is locked for a minute. */
    public static function pinLockedOut(string $till, ?string $tenantId): void
    {
        self::write('pin_locked_out', $tenantId, null, ['till' => $till]);
    }

    /** A real PIN was typed on the manager keypad, but that person may not approve this (e.g. a waiter's own PIN). */
    public static function approvalRefused(User $user, string $permission, string $till): void
    {
        self::write('approval_refused', $user->tenant_id, $user, ['till' => $till, 'needs' => $permission]);
    }

    /** Wrong email or password on the back-office or head-office sign-in page. */
    public static function loginFailed(?string $email, ?User $matched, string $panel): void
    {
        self::write('login_failed', $matched?->tenant_id, $matched, ['email' => $email, 'panel' => $panel]);
    }

    /** Too many wrong passwords: the sign-in page is blocked for a while. */
    public static function loginThrottled(?string $email, string $panel): void
    {
        self::write('login_throttled', null, null, ['email' => $email, 'panel' => $panel]);
    }

    /** Someone signed in at a till with a PIN tried to open the back office (test report H1). */
    public static function tillSessionRefused(?User $user, string $panel): void
    {
        self::write('till_session_refused', $user?->tenant_id, $user, ['panel' => $panel]);
    }

    private static function write(string $event, ?string $tenantId, ?User $user, array $properties): void
    {
        try {
            $logger = activity(self::LOG)->event($event)
                ->withProperties($properties + ['ip' => request()?->ip(), 'device' => substr((string) request()?->userAgent(), 0, 120)])
                ->tap(function (Activity $activity) use ($tenantId) {
                    // Before anyone is signed in there is no tenant yet: use this store server's restaurant.
                    $activity->tenant_id ??= $tenantId ?? app(TenantContext::class)->tenantId() ?? app(StoreTenantResolver::class)->tenant()?->getKey();
                });
            if ($user) {
                $logger->causedBy($user)->performedOn($user);
            }
            $logger->log($event);
        } catch (Throwable) {
            // Logging must never stop someone signing in or block the till.
        }
    }
}
