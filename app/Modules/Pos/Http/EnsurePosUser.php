<?php

namespace App\Modules\Pos\Http;

use App\Modules\Staff\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** POS screens need a PIN-logged-in user who is allowed on the till. */
class EnsurePosUser
{
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

        return $next($request);
    }
}
