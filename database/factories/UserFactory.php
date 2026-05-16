<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id'               => (string) Str::ulid(),
            'name'                    => fake()->name(),
            'email'                   => fake()->unique()->safeEmail(),
            'avatar_url'              => fake()->imageUrl(96, 96, 'people'),
            'credits_balance'         => 1,
            'credits_lifetime_earned' => 1,
            'credits_lifetime_spent'  => 0,
            'last_login_at'           => now(),
            'remember_token'          => Str::random(10),
        ];
    }

    /**
     * Empty-balance state for credit-exhaustion tests.
     */
    public function noCredits(): static
    {
        return $this->state(fn () => [
            'credits_balance'        => 0,
            'credits_lifetime_spent' => 1,
        ]);
    }
}
