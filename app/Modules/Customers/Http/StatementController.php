<?php

namespace App\Modules\Customers\Http;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Printable account statement. */
class StatementController
{
    public function __invoke(Request $request, Customer $customer, AccountService $accounts): View
    {
        abort_unless($request->user()->can(Permission::CustomersManage->value), 403);
        Tenancy::assertOwns($customer);

        return view('customers::statement', [
            'customer' => $customer,
            'rows' => $accounts->statement($customer, $request->date('from')?->toDateString(), $request->date('to')?->toDateString()),
            'branch' => Tenancy::branch(),
            'tenant' => Tenancy::branch()?->tenant,
        ]);
    }
}
