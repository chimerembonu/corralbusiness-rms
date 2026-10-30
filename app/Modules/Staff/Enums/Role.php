<?php

namespace App\Modules\Staff\Enums;

/** System roles (CLAUDE.md §3.10). Stored in the roles table by RolesAndPermissionsSeeder. */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';
    case Rider = 'rider';
    case Inventory = 'inventory';
    case Accountant = 'accountant';

    public function label(): string
    {
        return __('rms.roles.'.$this->value);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
