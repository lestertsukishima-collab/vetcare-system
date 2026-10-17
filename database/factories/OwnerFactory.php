<?php

namespace Database\Factories;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Owner> */
class OwnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // This factory creates the profile itself; avoid the User saved hook creating it twice.
            'user_id' => fn () => User::factory()->owner()->createQuietly()->id,
            'phone' => fake()->numerify('09#########'),
            'address' => fake()->streetAddress().', Sample City',
        ];
    }
}
