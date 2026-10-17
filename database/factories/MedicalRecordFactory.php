<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MedicalRecord> */
class MedicalRecordFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory()->completed(),
            // The model derives pet_id and veterinarian_id from this appointment.
            'symptoms' => 'Sample findings recorded during a routine visit.',
            'diagnosis' => 'Sample clinical assessment.',
            'treatment' => 'Sample follow-up plan.',
            'notes' => 'Demonstration data only; not clinical instructions.',
            'created_at' => fn (array $attributes) => Appointment::whereKey($attributes['appointment_id'])->firstOrFail()->appointment_date,
        ];
    }
}
