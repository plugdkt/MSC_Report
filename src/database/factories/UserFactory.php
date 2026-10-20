<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => Str::lower(fake()->unique()->userName()),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'pos_name' => 'นักวิชาการเงินและบัญชี',
            'div_name' => 'สำนักงานธุรการ',
            'role' => 'staff',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
