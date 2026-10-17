<?php

namespace Database\Factories;

use App\Models\Owner;
use App\Models\Pet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pet> */
class PetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => Owner::factory(),
            'name' => fake()->randomElement(['Milo', 'Luna', 'Coco', 'Max', 'Bella', 'Nala', 'Oreo', 'Charlie']),
            'species' => 'Dog',
            'breed' => 'Mixed breed',
            'sex' => fake()->randomElement(['Male', 'Female', 'Unknown']),
            'birth_date' => fake()->dateTimeBetween('-12 years', '-6 months')->format('Y-m-d'),
            'weight' => fake()->randomFloat(2, 2, 30),
            'color' => fake()->randomElement(['Black', 'White', 'Brown', 'Golden', 'Black and white']),
            'notes' => 'Sample patient profile for development.',
        ];
    }

    public function cat(): static
    {
        return $this->state(fn () => ['species' => 'Cat', 'breed' => 'Domestic shorthair', 'weight' => fake()->randomFloat(2, 2, 6)]);
    }
}
