<?php

namespace Database\Factories;

use App\Models\MedicalRecord;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Prescription> */
class PrescriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['medical_record_id' => MedicalRecord::factory(), 'instructions' => 'Demonstration prescription; not clinical instructions.'];
    }
}
