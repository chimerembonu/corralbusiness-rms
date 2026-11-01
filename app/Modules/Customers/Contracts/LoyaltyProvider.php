<?php

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;

/**
 * Points / rewards behind one interface so Corral Rewards (or any scheme) can replace the
 * built-in simple points later without touching the till.
 */
interface LoyaltyProvider
{
    /** Is the scheme switched on for this restaurant? */
    public function isEnabled(?string $branchId = null): bool;

    /** Points a paid order earns (0 when off). */
    public function pointsFor(Order $order): int;

    /** Credit the points for a paid order and return the new balance. */
    public function award(Customer $customer, Order $order): int;

    public function balance(Customer $customer): int;

    /** Human description shown on the till, e.g. "1 point per GH₵ 1". */
    public function describe(?string $branchId = null): ?string;
}
