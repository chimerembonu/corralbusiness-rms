<?php

namespace App\Modules\Orders\Support;

use App\Modules\Settings\Services\SettingsService;

/**
 * Settings the till reads on every order (Back office → Till settings).
 * Keys live in the settings table; these are the defaults when nothing was saved yet.
 */
final class TillSettings
{
    public const SERVICE_CHARGE_BP = 'service_charge.rate_bp';          // 1000 = 10 %

    public const SERVICE_CHARGE_TYPES = 'service_charge.order_types';   // ['dine_in']

    public const DISCOUNT_THRESHOLD_BP = 'discounts.manager_threshold_bp'; // discounts above this % of the subtotal need a manager PIN

    public const DISCOUNT_REASONS = 'discounts.reasons';

    public const VOID_REASONS = 'voids.reasons';

    public const TIPS_ENABLED = 'tips.enabled';

    public const COURSES = 'orders.courses';                             // ['Starters', 'Mains', 'Desserts']

    public const DELIVERY_FEE = 'delivery.default_fee';                  // pesewas, suggested on every new delivery order

    public const CASH_DEFAULT_FLOAT = 'cash.default_float';              // pesewas suggested when a till is opened

    public const CASH_VARIANCE_TOLERANCE = 'cash.variance_tolerance';    // pesewas; a cash-up short/over by more needs a manager PIN

    public const CASH_PAY_OUT_LIMIT = 'cash.pay_out_limit';              // pesewas; a pay-out above this needs a manager PIN (0 = every pay-out does)

    public const CASH_PAY_IN_REASONS = 'cash.pay_in_reasons';

    public const CASH_PAY_OUT_REASONS = 'cash.pay_out_reasons';

    public const CASH_REQUIRE_SESSION = 'cash.require_session';          // a till must be opened (float counted) before taking payments

    public const LOYALTY_POINTS_PER_CEDI = 'loyalty.points_per_cedi';    // 0 = points off

    public const TILL_LOCK_MINUTES = 'security.till_lock_minutes';       // a till nobody touches goes back to the PIN screen (E9.2, D1); 0 = never

    public const BACK_OFFICE_IDLE_MINUTES = 'security.back_office_idle_minutes'; // the back office signs out after this long unused (E9.2); 0 = never

    public const SECOND_APPROVAL_REFUND_ABOVE = 'security.second_approval_refund_above'; // pesewas; refunds on an order above this need a second manager's PIN (E9.4); 0 = never

    public const SECOND_APPROVAL_DAY_CLOSE = 'security.second_approval_day_close'; // closing the day needs a second manager's PIN (E9.4)

    public const CUSTOMER_RETENTION_MONTHS = 'customers.retention_months'; // restaurant-wide: customers' details are removed after this long without a visit (E9.3); 0 = kept

    /** The shortest retention period allowed (E9.6 review M2), so a typo such as "1" cannot wipe nearly every customer overnight. */
    public const MIN_CUSTOMER_RETENTION_MONTHS = 6;

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::SERVICE_CHARGE_BP => 0,
            self::SERVICE_CHARGE_TYPES => ['dine_in'],
            self::DISCOUNT_THRESHOLD_BP => 1000,
            self::DISCOUNT_REASONS => ['Staff meal', 'Loyal customer', 'Complaint', 'Owner / manager', 'Promotion'],
            self::VOID_REASONS => ['Customer changed mind', 'Wrong item entered', 'Kitchen mistake', 'Sold out', 'Customer left'],
            self::TIPS_ENABLED => true,
            self::COURSES => ['Starters', 'Mains', 'Desserts'],
            self::DELIVERY_FEE => 0,
            self::CASH_DEFAULT_FLOAT => 50000,
            self::CASH_VARIANCE_TOLERANCE => 500,
            self::CASH_PAY_OUT_LIMIT => 20000,
            self::CASH_PAY_IN_REASONS => ['Change from the bank', 'Float top-up', 'Owner / manager'],
            self::CASH_PAY_OUT_REASONS => ['Supplier paid in cash', 'Bank deposit', 'Staff advance', 'Petty cash', 'Tips paid to staff'],
            self::CASH_REQUIRE_SESSION => true,
            self::LOYALTY_POINTS_PER_CEDI => 0,
            self::TILL_LOCK_MINUTES => 5,
            self::BACK_OFFICE_IDLE_MINUTES => 30,
            self::SECOND_APPROVAL_REFUND_ABOVE => 0,
            self::SECOND_APPROVAL_DAY_CLOSE => false,
            self::CUSTOMER_RETENTION_MONTHS => 0,
        ];
    }

    public function tillLockMinutes(?string $branchId = null): int
    {
        return max(0, (int) $this->get(self::TILL_LOCK_MINUTES, $branchId));
    }

    public function backOfficeIdleMinutes(?string $branchId = null): int
    {
        return max(0, (int) $this->get(self::BACK_OFFICE_IDLE_MINUTES, $branchId));
    }

    public function secondApprovalRefundAbove(?string $branchId = null): int
    {
        return max(0, (int) $this->get(self::SECOND_APPROVAL_REFUND_ABOVE, $branchId));
    }

    public function secondApprovalDayClose(?string $branchId = null): bool
    {
        return (bool) $this->get(self::SECOND_APPROVAL_DAY_CLOSE, $branchId);
    }

    /** Restaurant-wide (customers belong to the whole restaurant, not one branch). */
    public function customerRetentionMonths(): int
    {
        $months = max(0, (int) $this->settings->get(self::CUSTOMER_RETENTION_MONTHS, 0, null));

        return $months > 0 ? max($months, self::MIN_CUSTOMER_RETENTION_MONTHS) : 0;
    }

    public function get(string $key, ?string $branchId = null): mixed
    {
        return $this->settings->get($key, self::defaults()[$key] ?? null, $branchId);
    }

    public function serviceChargeBp(?string $branchId = null): int
    {
        return (int) $this->get(self::SERVICE_CHARGE_BP, $branchId);
    }

    /** @return array<int, string> order type values the service charge applies to */
    public function serviceChargeOrderTypes(?string $branchId = null): array
    {
        return array_values((array) $this->get(self::SERVICE_CHARGE_TYPES, $branchId));
    }

    public function discountThresholdBp(?string $branchId = null): int
    {
        return (int) $this->get(self::DISCOUNT_THRESHOLD_BP, $branchId);
    }

    /** @return array<int, string> */
    public function discountReasons(?string $branchId = null): array
    {
        return array_values(array_filter((array) $this->get(self::DISCOUNT_REASONS, $branchId)));
    }

    /** @return array<int, string> */
    public function voidReasons(?string $branchId = null): array
    {
        return array_values(array_filter((array) $this->get(self::VOID_REASONS, $branchId)));
    }

    public function tipsEnabled(?string $branchId = null): bool
    {
        return (bool) $this->get(self::TIPS_ENABLED, $branchId);
    }

    public function deliveryFee(?string $branchId = null): int
    {
        return (int) $this->get(self::DELIVERY_FEE, $branchId);
    }

    public function defaultFloat(?string $branchId = null): int
    {
        return (int) $this->get(self::CASH_DEFAULT_FLOAT, $branchId);
    }

    public function varianceTolerance(?string $branchId = null): int
    {
        return (int) $this->get(self::CASH_VARIANCE_TOLERANCE, $branchId);
    }

    /** Pay-outs above this (pesewas) need a manager PIN; 0 means every pay-out does. */
    public function payOutLimit(?string $branchId = null): int
    {
        return max(0, (int) $this->get(self::CASH_PAY_OUT_LIMIT, $branchId));
    }

    /** @return array<int, string> */
    public function payInReasons(?string $branchId = null): array
    {
        return array_values(array_filter((array) $this->get(self::CASH_PAY_IN_REASONS, $branchId)));
    }

    /** @return array<int, string> */
    public function payOutReasons(?string $branchId = null): array
    {
        return array_values(array_filter((array) $this->get(self::CASH_PAY_OUT_REASONS, $branchId)));
    }

    public function requireSession(?string $branchId = null): bool
    {
        return (bool) $this->get(self::CASH_REQUIRE_SESSION, $branchId);
    }

    /** @return array<int, string> course names, index 0 = course 1 */
    public function courses(?string $branchId = null): array
    {
        return array_values(array_filter((array) $this->get(self::COURSES, $branchId)));
    }
}
