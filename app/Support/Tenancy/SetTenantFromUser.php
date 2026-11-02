<?php

namespace App\Support\Tenancy;

use App\Modules\Pos\Services\CurrentTill;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: after login, every request works inside the user's tenant and in the branch of the
 * till this device is (security review M7), else the user's home branch, else the store server's branch.
 * EnsurePosUser rejects staff whose home branch is not the till's branch.
 */
class SetTenantFromUser
{
    public function __construct(private readonly TenantContext $context, private readonly StoreTenantResolver $store, private readonly CurrentTill $till) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->context->hasTenant() && $user->tenant_id) {
            $till = $this->till->get();
            $tillBranch = $till && $till->tenant_id === $user->tenant_id ? $till->branch : null;

            $this->context->set($user->tenant, $tillBranch ?? $user->branch ?? $this->store->branch($user->tenant));
        }

        return $next($request);
    }
}
