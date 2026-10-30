<?php

namespace App\Modules\Staff\Seeders;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;

/** Ten demo staff with roles and PINs (PINs are printed by rms:demo). Re-runnable. */
class StaffDemoSeeder
{
    /** @return array<int, array{name: string, role: string, pin: string}> */
    public static function staff(): array
    {
        return [
            ['name' => 'Akosua Mensah', 'role' => Role::Manager->value, 'pin' => '2468'],
            ['name' => 'Kofi Boateng', 'role' => Role::Cashier->value, 'pin' => '1001'],
            ['name' => 'Esi Owusu', 'role' => Role::Cashier->value, 'pin' => '1002'],
            ['name' => 'Yaw Asante', 'role' => Role::Waiter->value, 'pin' => '2001'],
            ['name' => 'Abena Darko', 'role' => Role::Waiter->value, 'pin' => '2002'],
            ['name' => 'Kwame Ofori', 'role' => Role::Waiter->value, 'pin' => '2003'],
            ['name' => 'Nana Appiah', 'role' => Role::Kitchen->value, 'pin' => '3001'],
            ['name' => 'Adjoa Sarpong', 'role' => Role::Kitchen->value, 'pin' => '3002'],
            ['name' => 'Kwesi Amoah', 'role' => Role::Rider->value, 'pin' => '4001'],
            ['name' => 'Efua Agyeman', 'role' => Role::Inventory->value, 'pin' => '5001'],
        ];
    }

    public function run(): int
    {
        foreach (self::staff() as $i => $staff) {
            $user = User::query()->where('name', $staff['name'])->first() ?? new User;
            $user->fill([
                'name' => $staff['name'],
                'phone' => '+23324'.str_pad((string) (1000000 + $i * 11111), 7, '0', STR_PAD_LEFT),
                'pin' => $staff['pin'],
                'is_active' => true,
            ]);
            $user->tenant_id = Tenancy::tenantId();
            $user->branch_id = Tenancy::branchId();
            $user->save();
            $user->syncRoles([$staff['role']]);
        }

        return count(self::staff());
    }
}
