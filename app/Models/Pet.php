<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonInterface|null $birth_date */
class Pet extends ClinicModel
{
    /** @use HasFactory<PetFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['birth_date' => 'date', 'weight' => 'decimal:2'];
    }

    /** @return BelongsTo<Owner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<MedicalRecord, $this> */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class);
    }

    /** @return HasMany<Vaccination, $this> */
    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class);
    }
}
