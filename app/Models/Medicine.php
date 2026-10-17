<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MedicineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonInterface $expires_at */
class Medicine extends ClinicModel
{
    /** @use HasFactory<MedicineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'date', 'unit_price' => 'decimal:2', 'quantity' => 'integer', 'active' => 'boolean'];
    }

    /** @return HasMany<InventoryTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /** @return HasMany<PrescriptionItem, $this> */
    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
