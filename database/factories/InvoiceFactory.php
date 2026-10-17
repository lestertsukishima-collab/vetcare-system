<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'appointment_id' => fn () => MedicalRecord::factory()->create()->appointment_id,
            'service_amount' => fn (array $attributes) => Appointment::whereKey($attributes['appointment_id'])->firstOrFail()->service_fee,
            'medicine_amount' => fn (array $attributes) => (Appointment::whereKey($attributes['appointment_id'])->firstOrFail()->medicalRecord?->prescription?->items->sum(fn ($item) => Invoice::cents($item->unit_price) * $item->dispensed_quantity) ?? 0) / 100,
            'total' => fn (array $attributes) => (Invoice::cents($attributes['service_amount']) + Invoice::cents($attributes['medicine_amount'])) / 100,
            'due_date' => fn (array $attributes) => Appointment::whereKey($attributes['appointment_id'])->firstOrFail()->appointment_date->copy()->addDays(7)->toDateString(),
        ];
    }
}
