<?php

namespace App\Modules\Staff\Support;

use App\Modules\Staff\Enums\Permission as P;
use App\Modules\Staff\Enums\Role;

/** Which permissions each role gets. Edit here, then run `php artisan db:seed --class=RolesAndPermissionsSeeder`. */
final class RoleDefinitions
{
    /** @return array<string, array<int, string>> role value → permission values */
    public static function matrix(): array
    {
        $floor = [P::PosAccess, P::OrdersCreate, P::StaffClock, P::KitchenView];
        $manager = array_merge($floor, [
            P::AdminAccess, P::OrdersVoid, P::OrdersDiscount, P::OrdersRefund, P::OrdersPriceEdit,
            P::DayReopen, P::DayClose, P::PaymentsTake, P::CashDrawer, P::CashVariance, P::KitchenBump,
            P::MenuManage, P::Menu86, P::InventoryManage, P::InventoryCount, P::CustomersManage,
            P::StaffManage, P::ReportsView, P::ReportsExport, P::SettingsManage,
        ]);

        return [
            Role::Owner->value => P::values(),
            Role::Manager->value => self::values($manager),
            Role::Cashier->value => self::values(array_merge($floor, [P::PaymentsTake, P::CashDrawer, P::Menu86, P::CustomersManage])), // day close + big variances need a manager PIN
            Role::Waiter->value => self::values(array_merge($floor, [P::Menu86])),
            Role::Kitchen->value => self::values([P::PosAccess, P::StaffClock, P::KitchenView, P::KitchenBump, P::Menu86]),
            Role::Rider->value => self::values([P::PosAccess, P::StaffClock, P::DeliveryRide]),
            Role::Inventory->value => self::values([P::PosAccess, P::StaffClock, P::AdminAccess, P::InventoryManage, P::InventoryCount, P::ReportsView]),
            Role::Accountant->value => self::values([P::AdminAccess, P::ReportsView, P::ReportsExport]),
        ];
    }

    /** @param  array<int, P>  $permissions */
    private static function values(array $permissions): array
    {
        return array_values(array_unique(array_map(fn (P $p) => $p->value, $permissions)));
    }
}
