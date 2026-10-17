<?php

namespace Database\Factories;

use App\Models\InventoryTransaction;
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Medicine> */
class MedicineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Sample '.fake()->unique()->bothify('???-#####'),
            'quantity' => 50,
            'reorder_level' => 10,
            'expires_at' => today()->addYear(),
            'unit' => 'unit',
            'unit_price' => fake()->randomElement([15, 25, 50, 120]),
            'active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Medicine $medicine) {
            InventoryTransaction::create(['medicine_id' => $medicine->id, 'quantity_change' => $medicine->quantity, 'balance_after' => $medicine->quantity, 'type' => 'opening', 'notes' => 'Factory opening balance.']);
        });
    }

    public function lowStock(): static
    {
        return $this->state(fn () => ['quantity' => 3, 'reorder_level' => 10]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => today()->subMonth()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
