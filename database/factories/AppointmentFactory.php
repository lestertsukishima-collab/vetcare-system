<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\Service;
use App\Models\Veterinarian;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pet_id' => Pet::factory(),
            'veterinarian_id' => Veterinarian::factory(),
            'service_id' => Service::factory(),
            'appointment_date' => now()->addDays(fake()->numberBetween(1, 14))->setTime(fake()->numberBetween(9, 16), 0),
            'reason' => 'Sample routine wellness consultation.',
            'status' => 'pending',
            'service_fee' => fn (array $attributes) => Service::whereKey($attributes['service_id'])->firstOrFail()->price,
            'service_name' => fn (array $attributes) => Service::whereKey($attributes['service_id'])->firstOrFail()->name,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => 'confirmed']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed', 'appointment_date' => now()->subDays(fake()->numberBetween(1, 60))->setTime(10, 0)]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
