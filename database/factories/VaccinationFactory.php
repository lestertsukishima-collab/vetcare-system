<?php

namespace Database\Factories;

use App\Models\MedicalRecord;
use App\Models\Vaccination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vaccination> */
class VaccinationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'medical_record_id' => MedicalRecord::factory(),
            'vaccine_name' => 'Sample vaccine',
            'date_given' => fn (array $attributes) => MedicalRecord::whereKey($attributes['medical_record_id'])->firstOrFail()->appointment->appointment_date->toDateString(),
            'next_due_date' => fn (array $attributes) => CarbonImmutable::parse($attributes['date_given'])->addYear()->toDateString(),
            'batch_number' => fake()->bothify('DEMO-LOT-######'),
        ];
    }
}
