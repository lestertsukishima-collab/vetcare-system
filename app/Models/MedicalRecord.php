<?php

namespace App\Models;

use Database\Factories\MedicalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property-read Prescription|null $prescription */
class MedicalRecord extends ClinicModel
{
    /** @use HasFactory<MedicalRecordFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (MedicalRecord $record) {
            $appointment = $record->appointment()->firstOrFail();
            $record->pet_id = $appointment->pet_id;
            $record->veterinarian_id = $appointment->veterinarian_id;
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

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return HasOne<Prescription, $this> */
    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class);
    }

    /** @return HasMany<Vaccination, $this> */
    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class);
    }
}
