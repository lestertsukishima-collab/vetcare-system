<?php

namespace App\Services;

use App\Models\User;

class ClinicAccess
{
    public const ADMIN = ['owners', 'veterinarians', 'services', 'inventory', 'users', 'reports', 'settings', 'transactions', 'payments'];

    public function authorize(User $user, string $section): void
    {
        abort_unless(in_array($section, ['dashboard', 'pets', 'appointments', 'records', 'vaccinations', 'prescriptions', 'invoices', ...self::ADMIN]), 404);
        abort_unless($user->active && in_array($user->role, ['admin', 'veterinarian', 'owner']), 403);
        abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email address before using the clinic workspace.');
        abort_if(in_array($section, self::ADMIN) && $user->role !== 'admin', 403);
        abort_if($section === 'invoices' && ! in_array($user->role, ['admin', 'owner']), 403);
    }
}
