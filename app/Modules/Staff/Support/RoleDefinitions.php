<?php

namespace App\Modules\Staff\Support;

use App\Modules\Staff\Enums\Permission as P;
use App\Modules\Staff\Enums\Role;

/** Which permissions each role gets. Edit here; the store server re-applies this on every start (docker/entrypoint.sh). On the Mac run `php artisan db:seed --class=RolesAndPermissionsSeeder`. */
final class RoleDefinitions
{
    /** @return array<string, array<int, string>> role value → permission values */
    public static function matrix(): array
    {
        // Front of house. Kitchen screen, small discounts and reports are added per role below (owner's decisions, 4 Nov 2026).
        $floor = [P::PosAccess, P::OrdersCreate, P::StaffClock];
        $manager = array_merge($floor, [
            P::KitchenView, P::DiscountSmall,
            P::AdminAccess, P::OrdersVoid, P::OrdersDiscount, P::OrdersRefund, P::OrdersPriceEdit,
            P::DayReopen, P::DayClose, P::TrainingMode, P::PaymentsTake, P::CashDrawer, P::CashVariance, P::CashPayOutLarge, P::KitchenBump,
            P::MenuManage, P::Menu86, P::InventoryManage, P::InventoryCount, P::CustomersManage,
            P::StaffManage, P::ReportsView, P::ReportsExport, P::SettingsManage,
        ]);

        return [
            Role::Owner->value => P::values(),
            Role::Manager->value => self::values($manager),
            // Day close and big variances need a manager PIN. No kitchen screen.
            Role::Cashier->value => self::values(array_merge($floor, [P::PaymentsTake, P::CashDrawer, P::Menu86, P::CustomersManage, P::DiscountSmall])),
            // Every waiter discount or promo code needs a manager PIN. No kitchen screen.
            Role::Waiter->value => self::values(array_merge($floor, [P::Menu86])),
            Role::Kitchen->value => self::values([P::PosAccess, P::StaffClock, P::KitchenView, P::KitchenBump, P::Menu86]),
            Role::Rider->value => self::values([P::PosAccess, P::StaffClock, P::DeliveryRide]),
            // Stock only: no sales or cash figures (no reports.view).
            Role::Inventory->value => self::values([P::PosAccess, P::StaffClock, P::AdminAccess, P::InventoryManage, P::InventoryCount]),
            Role::Accountant->value => self::values([P::AdminAccess, P::ReportsView, P::ReportsExport]),
        ];
    }

    /** @param  array<int, P>  $permissions */
    private static function values(array $permissions): array
    {
        return array_values(array_unique(array_map(fn (P $p) => $p->value, $permissions)));
    }
}
