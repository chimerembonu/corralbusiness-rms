<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles and permissions are always needed. Demo restaurant data: `php artisan rms:demo` (Step 0.6).
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
