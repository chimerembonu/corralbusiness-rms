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
        ];
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

    /** @return array<int, string> course names, index 0 = course 1 */
    public function courses(?string $branchId = null): array
    {
        return array_values(array_filter((array) $this->get(self::COURSES, $branchId)));
    }
}
