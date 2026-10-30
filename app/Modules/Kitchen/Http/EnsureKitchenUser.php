<?php

namespace App\Modules\Kitchen\Http;

use App\Modules\Staff\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Kitchen screens: PIN-logged-in user with the kitchen.view permission. */
class EnsureKitchenUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('pos.login'));
        }

        if (! $user->is_active || ! $user->can(Permission::KitchenView->value)) {
            abort(403, __('kitchen::kitchen.no_access'));
        }

        return $next($request);
    }
}
