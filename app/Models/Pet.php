<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property CarbonInterface|null $birth_date
 * @property string|null $photo_path
 */
class Pet extends ClinicModel
{
    protected $guarded = ['id', 'photo_path'];

    protected $hidden = ['photo_path'];

    /** @use HasFactory<PetFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (Pet $pet): void {
            if ($pet->photo_path) {
                Storage::disk('local')->delete($pet->photo_path);
            }
        });
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('pets.photo', [
            'pet' => $this->id, 'version' => basename($this->photo_path),
        ]) : null;
    }

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
