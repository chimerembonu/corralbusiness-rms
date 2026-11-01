<?php

namespace App\Modules\Orders\Seeders;

use App\Modules\Orders\Models\PromoCode;

/** Two demo promo codes. Re-runnable. */
class PromoCodesDemoSeeder
{
    public function run(): int
    {
        PromoCode::query()->updateOrCreate(['code' => 'WELCOME10'], [
            'name' => 'Welcome 10% off', 'type' => 'percent', 'value' => 1000, 'max_discount' => 5000, 'is_active' => true,
        ]);
        PromoCode::query()->updateOrCreate(['code' => 'FRIDAY20'], [
            'name' => 'Friday GH₵ 20 off big orders', 'type' => 'fixed', 'value' => 2000, 'min_order' => 15000, 'is_active' => true,
        ]);

        return PromoCode::count();
    }
}
