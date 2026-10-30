<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware: after login, every request works inside the user's tenant and branch. */
class SetTenantFromUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->context->hasTenant() && $user->tenant_id) {
            $this->context->set($user->tenant, $user->branch);
        }

        return $next($request);
    }
}
