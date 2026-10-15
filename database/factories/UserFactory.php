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
    protected $model = User::class;

    /** Default: a social-login reader (no password). */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => null,
            'avatar_url' => null,
            'role' => 'user',
            'provider_name' => fake()->randomElement(['google', 'discord']),
            'provider_id' => (string) fake()->unique()->numberBetween(10_000_000, 999_999_999),
            'remember_token' => Str::random(10),
        ];
    }

    /** Staff accounts use passwords, not social login. Demo password: "password" (local only). */
    public function editor(): static
    {
        return $this->state(fn () => [
            'role' => 'editor',
            'provider_name' => null,
            'provider_id' => null,
            'password' => 'password',
        ]);
    }
}