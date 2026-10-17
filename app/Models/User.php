<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $role
 * @property bool $active
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    protected $attributes = ['role' => 'owner', 'active' => true];

    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if ($user->role === 'owner') {
                $user->owner()->firstOrCreate();
            }
            if ($user->role === 'veterinarian') {
                $user->veterinarian()->firstOrCreate();
            }
        });
        static::deleting(function (User $user) {
            if ($user->owner?->pets()->exists() || $user->veterinarian?->appointments()->exists() || $user->role === 'admin' ||
                Payment::where('recorded_by', $user->id)->exists() || InventoryTransaction::where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['password' => 'This account has clinic records or administrative access. An administrator can deactivate it while preserving clinic history.']);
            }
            $user->owner()->delete();
            $user->veterinarian()->delete();
        });
    }

    /** @return HasOne<Owner, $this> */
    public function owner(): HasOne
    {
        return $this->hasOne(Owner::class);
    }

    /** @return HasOne<Veterinarian, $this> */
    public function veterinarian(): HasOne
    {
        return $this->hasOne(Veterinarian::class);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
