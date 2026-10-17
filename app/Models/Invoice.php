<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonInterface $due_date */
class Invoice extends ClinicModel
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_date' => 'date', 'service_amount' => 'decimal:2', 'medicine_amount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balanceCents(): int
    {
        return max(0, self::cents($this->total) - $this->payments->sum(fn ($p) => self::cents($p->amount)));
    }

    public static function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
