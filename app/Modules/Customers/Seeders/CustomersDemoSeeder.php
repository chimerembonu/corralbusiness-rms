<?php

namespace App\Modules\Customers\Seeders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Support\Money\Money;

/** Five regulars, one of them buying on account. Re-runnable. */
class CustomersDemoSeeder
{
    public function run(): int
    {
        $customers = [
            ['name' => 'Ama Serwaa', 'phone' => '+233 24 123 4567', 'address' => 'House 14, Oxford Street, Osu', 'notes' => 'Likes extra pepper'],
            ['name' => 'Kwabena Osei', 'phone' => '+233 20 765 4321', 'address' => 'Labone, near the Methodist church'],
            ['name' => 'Golden Tulip front desk', 'phone' => '+233 30 221 3344', 'address' => 'Golden Tulip Hotel, Liberation Road', 'allow_account' => true, 'credit_limit' => Money::fromDecimal('2000')],
            ['name' => 'Nana Yaa', 'phone' => '+233 55 999 1111', 'address' => 'East Legon, American House junction'],
            ['name' => 'Chop Bar Regular Joe', 'phone' => '+233 27 000 7777'],
        ];

        $service = app(CustomerService::class);
        foreach ($customers as $data) {
            if ($service->findByPhone($data['phone'])) {
                continue;
            }
            $service->create($data);
        }

        return Customer::count();
    }
}
