<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionItem> */
class PrescriptionItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'medicine_id' => Medicine::factory(),
            'quantity' => 2,
            // Supply medicines through ClinicWorkflow::examine so stock and invoices stay in sync.
            'dispensed_quantity' => 0,
            'dosage' => 'Sample directions only; not for clinical use.',
            'unit_price' => fn (array $attributes) => Medicine::whereKey($attributes['medicine_id'])->firstOrFail()->unit_price,
            'medicine_name' => fn (array $attributes) => Medicine::whereKey($attributes['medicine_id'])->firstOrFail()->name,
            'medicine_unit' => fn (array $attributes) => Medicine::whereKey($attributes['medicine_id'])->firstOrFail()->unit,
        ];
    }
}
