<?php

namespace App\Modules\Pos\Http;

use App\Modules\Orders\Support\TillSettings;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use App\Support\Http\UserActivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** POS screens need a PIN-logged-in user who is allowed on the till. */
class EnsurePosUser
{
    public const LAST_ACTIVITY = 'pos.last_activity';

    public function __construct(private readonly CurrentTill $till) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('pos.login');
        }

        if (! $user->is_active || ! $user->can(Permission::PosAccess->value)) {
            Auth::logout();

            return redirect()->route('pos.login')->with('error', __('pos::pos.login.no_access'));
        }

        // Staff work in the branch of the till they are on; someone from another branch is sent back (security review M7).
        $till = $this->till->get();
        if ($till && $user->branch_id !== null && $till->branch_id !== $user->branch_id) {
            Auth::logout();

            return redirect()->route('pos.login')->with('error', __('pos::pos.login.wrong_branch'));
        }

        // Idle lock (E9.2, security review D1): a till nobody has touched for the set minutes goes back to the
        // PIN screen. The screen's own timer does this on the spot; this catches the next request too.
        $minutes = app(TillSettings::class)->tillLockMinutes();
        $last = (int) $request->session()->get(self::LAST_ACTIVITY, 0);
        if ($minutes > 0 && $last > 0 && now()->getTimestamp() - $last > $minutes * 60) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('pos.login')->with('status', __('pos::pos.login.locked_idle', ['minutes' => $minutes]));
        }
        if (UserActivity::isPerson($request)) {
            $request->session()->put(self::LAST_ACTIVITY, now()->getTimestamp());
        }

        return $next($request);
    }
}
