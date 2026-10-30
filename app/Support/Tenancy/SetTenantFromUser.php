<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: after login, every request works inside the user's tenant and branch.
 * Users without a home branch (e.g. the owner) work in the store server's branch.
 */
class SetTenantFromUser
{
    public function __construct(private readonly TenantContext $context, private readonly StoreTenantResolver $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->context->hasTenant() && $user->tenant_id) {
            $this->context->set($user->tenant, $user->branch ?? $this->store->branch($user->tenant));
        }

        return $next($request);
    }
}
