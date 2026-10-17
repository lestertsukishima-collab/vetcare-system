<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicAccounts
{
    /** @param array{name: string, email: string, active: bool|int|string, role?: string, phone?: ?string, address?: ?string, license_number?: ?string, specialization?: ?string} $data */
    public function update(User $actor, User $target, array $data): User
    {
        abort_unless($actor->role === 'admin', 403);

        return DB::transaction(function () use ($actor, $target, $data) {
            // Serialize admin removal so concurrent changes cannot remove the last active admin.
            $admins = User::where('role', 'admin')->where('active', true)->orderBy('id')->lockForUpdate()->get();
            $account = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $role = $data['role'] ?? $account->role;
            $active = (bool) $data['active'];
            if ($account->id === $actor->id && ! $active) {
                throw ValidationException::withMessages(['active' => 'You cannot deactivate your own account.']);
            }
            if ($account->role === 'admin' && $account->active && ($role !== 'admin' || ! $active) && $admins->where('id', '!=', $account->id)->isEmpty()) {
                throw ValidationException::withMessages(['role' => 'Keep at least one active administrator. Create another admin before changing this account.']);
            }
            if ($account->role === 'veterinarian' && ($role !== 'veterinarian' || ! $active)) {
                $profile = $account->veterinarian()->lockForUpdate()->first();
                if ($profile?->appointments()->whereIn('status', ['pending', 'confirmed'])->exists()) {
                    throw ValidationException::withMessages([$active ? 'role' : 'active' => 'Reassign or cancel this veterinarian’s open appointments before removing their access.']);
                }
            }
            $account->forceFill(['name' => $data['name'], 'email' => $data['email'], 'active' => $active, 'role' => $role])->save();
            // Old profiles remain linked to their history when a role changes.
            if ($role === 'owner') {
                $owner = $account->owner()->firstOrFail();
                $owner->update(['phone' => array_key_exists('phone', $data) ? $data['phone'] : $owner->phone, 'address' => array_key_exists('address', $data) ? $data['address'] : $owner->address]);
            }
            if ($role === 'veterinarian') {
                $vet = $account->veterinarian()->firstOrFail();
                $vet->update(['license_number' => array_key_exists('license_number', $data) ? $data['license_number'] : $vet->license_number, 'specialization' => array_key_exists('specialization', $data) ? $data['specialization'] : $vet->specialization]);
            }

            return $account;
        });
    }
}
