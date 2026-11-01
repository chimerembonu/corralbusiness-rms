<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\LoyaltyProvider;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Settings\Services\SettingsService;

/** Built-in scheme: N points per cedi spent (Settings key loyalty.points_per_cedi; 0 = off). */
class SimplePointsProvider implements LoyaltyProvider
{
    public const SETTING = 'loyalty.points_per_cedi';

    public function __construct(private readonly SettingsService $settings) {}

    public function rate(?string $branchId = null): int
    {
        return (int) $this->settings->get(self::SETTING, 0, $branchId);
    }

    public function isEnabled(?string $branchId = null): bool
    {
        return $this->rate($branchId) > 0;
    }

    public function pointsFor(Order $order): int
    {
        $rate = $this->rate($order->branch_id);

        return $rate > 0 ? intdiv($order->total->amount, 100) * $rate : 0;
    }

    public function award(Customer $customer, Order $order): int
    {
        $points = $this->pointsFor($order);
        if ($points > 0) {
            $customer->increment('points_balance', $points);
        }

        return $customer->fresh()->points_balance;
    }

    public function balance(Customer $customer): int
    {
        return $customer->points_balance;
    }

    public function describe(?string $branchId = null): ?string
    {
        return $this->isEnabled($branchId) ? trans_choice('customers::customers.loyalty.rate', $this->rate($branchId), ['points' => $this->rate($branchId)]) : null;
    }
}
