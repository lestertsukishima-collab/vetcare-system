<?php

namespace Database\Factories;

use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<InventoryTransaction> */
class InventoryTransactionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'medicine_id' => Medicine::factory()->state(['quantity' => 0]),
            'user_id' => User::factory()->admin(),
            'prescription_item_id' => null,
            'quantity_change' => 10,
            'balance_after' => fn (array $attributes) => Medicine::whereKey($attributes['medicine_id'])->firstOrFail()->quantity + $attributes['quantity_change'],
            'type' => 'received',
            'notes' => 'Sample stock receipt.',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (InventoryTransaction $transaction) {
            // A counted factory makes all rows before running its callbacks. Recalculate
            // from the current stock here so repeated receipts for one medicine reconcile.
            DB::transaction(function () use ($transaction) {
                $medicine = Medicine::lockForUpdate()->findOrFail($transaction->medicine_id);
                $balance = $medicine->quantity + $transaction->quantity_change;
                $transaction->update(['balance_after' => $balance]);
                $medicine->update(['quantity' => $balance]);
            });
        });
    }
}
