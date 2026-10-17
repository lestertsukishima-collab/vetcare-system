<?php

namespace App\Models;

use Database\Factories\PrescriptionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $dispensed_quantity */
class PrescriptionItem extends ClinicModel
{
    /** @use HasFactory<PrescriptionItemFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'quantity' => 'integer', 'dispensed_quantity' => 'integer'];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
