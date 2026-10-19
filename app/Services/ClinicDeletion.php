<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicDeletion
{
    public function delete(User $actor, string $section, int $id): void
    {
        app(ClinicAccess::class)->authorize($actor, $section);
        abort_unless(in_array($section, ['pets', 'services', 'inventory', 'users', 'owners', 'veterinarians']), 405, 'This resource is retained as clinic history.');

        try {
            DB::transaction(function () use ($actor, $section, $id) {
                switch ($section) {
                    case 'pets':
                        abort_unless(in_array($actor->role, ['admin', 'owner']), 403);
                        $pet = Pet::query()
                            ->when($actor->role === 'owner', fn ($q) => $q->whereHas('owner', fn ($q) => $q->where('user_id', $actor->id)))
                            ->lockForUpdate()->findOrFail($id);
                        if ($pet->appointments()->exists() || $pet->medicalRecords()->exists() || $pet->vaccinations()->exists()) {
                            $this->blocked('This pet has clinic history and cannot be deleted.');
                        }
                        $pet->delete();
                        break;
                    case 'services':
                        $service = Service::lockForUpdate()->findOrFail($id);
                        if ($service->appointments()->exists()) {
                            $this->blocked('This service has appointments. Set its status to inactive instead.');
                        }
                        $service->delete();
                        break;
                    case 'inventory':
                        $medicine = Medicine::lockForUpdate()->findOrFail($id);
                        if ($medicine->quantity !== 0 || $medicine->transactions()->exists() || $medicine->prescriptionItems()->exists()) {
                            $this->blocked('This medicine has stock or clinic history. Set its status to inactive instead.');
                        }
                        $medicine->delete();
                        break;
                    default:
                        $accountId = match ($section) {
                            'owners' => Owner::whereKey($id)->whereHas('user', fn ($q) => $q->where('role', 'owner'))->firstOrFail()->user_id,
                            'veterinarians' => Veterinarian::whereKey($id)->whereHas('user', fn ($q) => $q->where('role', 'veterinarian'))->firstOrFail()->user_id,
                            default => $id,
                        };
                        $account = User::lockForUpdate()->findOrFail($accountId);
                        if ($account->id === $actor->id || $account->role === 'admin') {
                            $this->blocked('Your own account and administrator accounts cannot be deleted.');
                        }
                        // Lock profiles used by booking before checking and removing the account.
                        $owner = $account->owner()->lockForUpdate()->first();
                        $vet = $account->veterinarian()->lockForUpdate()->first();
                        if ($owner?->pets()->exists() || $vet?->appointments()->exists() ||
                            Payment::where('recorded_by', $account->id)->exists() || InventoryTransaction::where('user_id', $account->id)->exists()) {
                            $this->blocked('This account has clinic history. Deactivate the account instead.');
                        }
                        $account->delete();
                        DB::table('sessions')->where('user_id', $account->id)->delete();
                        DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                        break;
                }
            });
        } catch (QueryException $exception) {
            // A concurrent booking or stock entry can acquire a reference after our checks.
            // Foreign keys provide the final safeguard; return a useful error to the caller.
            if (in_array((string) $exception->getCode(), ['23000', '23503'])) {
                $this->blocked('This record is linked to clinic history and cannot be deleted.');
            }

            throw $exception;
        }
    }

    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['delete' => $message]);
    }
}
