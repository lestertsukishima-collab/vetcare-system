<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Veterinarian> */
class VeterinarianFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory()->veterinarian()->createQuietly()->id,
            'license_number' => fake()->unique()->bothify('DEMO-VET-########'),
            'specialization' => fake()->randomElement(['General practice', 'Small animal care', 'Preventive care']),
        ];
    }
}
