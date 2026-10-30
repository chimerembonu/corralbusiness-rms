<?php

namespace Database\Factories;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+233'.fake()->numerify('#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn () => ['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id]);
    }

    public function withPin(string $pin): static
    {
        return $this->state(fn () => ['pin' => $pin]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }
}
