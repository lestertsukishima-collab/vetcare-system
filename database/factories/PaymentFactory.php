<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'recorded_by' => User::factory()->admin(),
            'amount' => fn (array $attributes) => intdiv(Invoice::whereKey($attributes['invoice_id'])->firstOrFail()->balanceCents(), 2) / 100,
            'method' => 'cash',
            'reference' => null,
            'paid_at' => now(),
            'request_key' => (string) Str::uuid(),
        ];
    }

    public function fullBalance(): static
    {
        return $this->state(fn () => ['amount' => fn (array $attributes) => Invoice::whereKey($attributes['invoice_id'])->firstOrFail()->balanceCents() / 100]);
    }
}
