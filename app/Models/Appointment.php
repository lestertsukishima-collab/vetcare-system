<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property CarbonInterface $appointment_date */
class Appointment extends ClinicModel
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['appointment_date' => 'datetime', 'service_fee' => 'decimal:2'];
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

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasOne<MedicalRecord, $this> */
    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'admin' => $query,
            'owner' => $query->whereHas('pet.owner', fn ($q) => $q->where('user_id', $user->id)),
            'veterinarian' => $query->whereHas('veterinarian', fn ($q) => $q->where('user_id', $user->id)),
            default => $query->whereRaw('1=0'),
        };
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeClinicalHistoryVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role !== 'veterinarian') {
            return $query->visibleTo($user);
        }

        return $query->whereIn('pet_id', static::visibleTo($user)->where('status', '!=', 'cancelled')->select('pet_id'));
    }
}
