<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'role' => User::ROLE_AGENT,
            'remember_token' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => User::ROLE_ADMIN]);
    }

    public function agent(): static
    {
        return $this->state(['role' => User::ROLE_AGENT]);
    }
}
