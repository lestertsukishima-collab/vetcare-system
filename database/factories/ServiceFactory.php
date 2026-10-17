<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Sample '.fake()->unique()->bothify('???-#####'),
            'price' => fake()->randomElement([350, 500, 650, 800, 1200]),
            'description' => 'Demonstration service for a sample clinic visit.',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
