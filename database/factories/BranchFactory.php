<?php

namespace Database\Factories;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->city().' Branch',
            'code' => Str::upper(Str::random(4)),
            'address' => fake()->streetAddress(),
            'phone' => '+233'.fake()->numerify('#########'),
            'is_active' => true,
        ];
    }
}
