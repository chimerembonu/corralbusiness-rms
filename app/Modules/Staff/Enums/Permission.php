<?php

namespace App\Modules\Staff\Enums;

/** Every permission in the system. Roles are built from these in RoleDefinitions. */
enum Permission: string
{
    case AdminAccess = 'admin.access';          // may open /admin
    case HqAccess = 'hq.access';                // may open /hq (all branches)
    case PosAccess = 'pos.access';              // may log in to a till with a PIN
    case OrdersCreate = 'orders.create';
    case OrdersVoid = 'orders.void';            // manager PIN override
    case OrdersDiscount = 'orders.discount';    // manager PIN override above threshold
    case OrdersRefund = 'orders.refund';        // manager PIN override
    case OrdersPriceEdit = 'orders.price_edit'; // manager PIN override
    case DayReopen = 'day.reopen';              // manager PIN override
    case DayClose = 'day.close';
    case PaymentsTake = 'payments.take';
    case CashDrawer = 'payments.cash_drawer';   // pay-in / pay-out / open drawer
    case KitchenView = 'kitchen.view';
    case KitchenBump = 'kitchen.bump';
    case DeliveryRide = 'delivery.ride';
    case MenuManage = 'menu.manage';
    case Menu86 = 'menu.sold_out';              // mark items sold out from POS
    case InventoryManage = 'inventory.manage';
    case InventoryCount = 'inventory.count';
    case CustomersManage = 'customers.manage';
    case StaffManage = 'staff.manage';
    case StaffClock = 'staff.clock';
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case SettingsManage = 'settings.manage';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
