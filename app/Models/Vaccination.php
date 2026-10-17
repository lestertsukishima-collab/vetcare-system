<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\VaccinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface $date_given
 * @property CarbonInterface|null $next_due_date
 */
class Vaccination extends ClinicModel
{
    /** @use HasFactory<VaccinationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Vaccination $vaccination) {
            $record = $vaccination->medicalRecord()->firstOrFail();
            $vaccination->pet_id = $record->pet_id;
            $vaccination->veterinarian_id = $record->veterinarian_id;
        });
    }

    /** @return BelongsTo<Pet, $this> */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /** @return BelongsTo<Veterinarian, $this> */
    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date_given' => 'date', 'next_due_date' => 'date'];
    }

    /** @return BelongsTo<MedicalRecord, $this> */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }
}
