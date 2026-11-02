<?php

namespace App\Modules\Pos\Http;

use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** POS screens need a PIN-logged-in user who is allowed on the till. */
class EnsurePosUser
{
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

        return $next($request);
    }
}
