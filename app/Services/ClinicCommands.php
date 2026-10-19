<?php

namespace App\Services;

use App\Concerns\ClinicalRowValidationRules;
use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\Owner;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Veterinarian;
use App\Rules\UniqueEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicCommands
{
    use ClinicalRowValidationRules;

    /** @param array<string, mixed> $input */
    public function store(User $user, string $section, array $input): ?Model
    {
        app(ClinicAccess::class)->authorize($user, $section);
        $workflow = app(ClinicWorkflow::class);
        $created = null;
        switch ($section) {
            case 'pets':
                abort_unless(in_array($user->role, ['admin', 'owner']), 403);
                $data = $this->validate($input, $this->petRules());
                $data['owner_id'] = $user->role === 'owner' ? $user->owner()->firstOrFail()->id : $this->validate($input, ['owner_id' => 'bail|required|integer|min:1|exists:owners,id'])['owner_id'];
                if ($user->role === 'admin') {
                    $owner = Owner::with('user')->whereKey($data['owner_id'])->firstOrFail();
                    if (! $owner->user->active || $owner->user->role !== 'owner') {
                        throw ValidationException::withMessages(['owner_id' => 'Choose an active pet owner account.']);
                    }
                }
                $created = Pet::create($data);
                break;
            case 'appointments':
                abort_unless(in_array($user->role, ['admin', 'owner']), 403);
                $booking = $this->validate($input, ['pet_id' => 'bail|required|integer|min:1|exists:pets,id', 'veterinarian_id' => 'bail|required|integer|min:1|exists:veterinarians,id', 'service_id' => 'bail|required|integer|min:1|exists:services,id', 'appointment_date' => 'required|date|after:now', 'reason' => 'required|string|max:5000']);
                $created = $workflow->book($user, ['pet_id' => $booking['pet_id'], 'veterinarian_id' => $booking['veterinarian_id'], 'service_id' => $booking['service_id'], 'appointment_date' => $booking['appointment_date'], 'reason' => $booking['reason']]);
                break;
            case 'records':
                abort_unless($user->role === 'veterinarian', 403);
                $data = $this->validate($input, [
                    'appointment_id' => 'bail|required|integer|min:1|exists:appointments,id', 'symptoms' => 'required|string|max:10000', 'diagnosis' => 'required|string|max:10000', 'treatment' => 'required|string|max:10000', 'notes' => 'nullable|string|max:10000',
                    'instructions' => 'nullable|string|max:5000', 'items' => ['nullable', 'array', 'max:20', $this->clinicalRowKeys()], 'items.*' => 'array:medicine_id,quantity,dosage,dispense_now', 'items.*.medicine_id' => 'bail|nullable|integer|min:1|distinct|exists:medicines,id|required_with:items.*.quantity,items.*.dosage',
                    'items.*.quantity' => 'nullable|required_with:items.*.medicine_id|integer|min:1|max:1000000', 'items.*.dosage' => 'nullable|required_with:items.*.medicine_id|string|max:255',
                    'items.*.dispense_now' => 'sometimes|boolean',
                    'vaccinations' => ['nullable', 'array', 'max:20', $this->clinicalRowKeys()], 'vaccinations.*' => 'array:vaccine_name,date_given,next_due_date,batch_number',
                    'vaccinations.*.vaccine_name' => 'nullable|string|max:200|required_with:vaccinations.*.date_given,vaccinations.*.next_due_date,vaccinations.*.batch_number',
                    'vaccinations.*.date_given' => 'nullable|required_with:vaccinations.*.vaccine_name|date|before_or_equal:today',
                    'vaccinations.*.next_due_date' => 'nullable|date|after:vaccinations.*.date_given',
                    'vaccinations.*.batch_number' => 'nullable|string|max:100',
                ]);
                $created = $workflow->examine($user, ['appointment_id' => $data['appointment_id'], 'symptoms' => $data['symptoms'], 'diagnosis' => $data['diagnosis'], 'treatment' => $data['treatment']] + $data);
                break;
            case 'services':
                $created = Service::create($this->validate($input, $this->serviceRules()));
                break;
            case 'inventory':
                $data = $this->validate($input, $this->medicineRules() + ['quantity' => 'required|integer|min:0|max:1000000']);
                $created = DB::transaction(function () use ($user, $data) {
                    $m = Medicine::create($data);
                    if ($m->quantity > 0) {
                        InventoryTransaction::create(['medicine_id' => $m->id, 'user_id' => $user->id, 'quantity_change' => $m->quantity, 'balance_after' => $m->quantity, 'type' => 'opening', 'notes' => 'Initial stock']);
                    }

                    return $m;
                });
                break;
            case 'owners': case 'veterinarians': case 'users':
                $data = $this->validate($input, ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', new UniqueEmail], 'password' => 'required|string|min:12|max:255', 'role' => ['required', Rule::in(['admin', 'owner', 'veterinarian'])], 'phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:1000', 'license_number' => 'nullable|string|max:100|unique:veterinarians,license_number', 'specialization' => 'nullable|string|max:100']);
                $created = DB::transaction(function () use ($data, $section) {
                    $u = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
                    $u->role = match ($section) {
                        'owners' => 'owner', 'veterinarians' => 'veterinarian', default => $data['role']
                    };
                    $u->email_verified_at = now();
                    $u->save();
                    if ($u->role === 'owner') {
                        $u->owner()->firstOrFail()->update(['phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null]);
                    }
                    if ($u->role === 'veterinarian') {
                        $u->veterinarian()->firstOrFail()->update(['license_number' => $data['license_number'] ?? null, 'specialization' => $data['specialization'] ?? null]);
                    }

                    return match ($section) {
                        'owners' => $u->owner()->firstOrFail(),
                        'veterinarians' => $u->veterinarian()->firstOrFail(),
                        default => $u,
                    };
                });
                break;
            case 'invoices':
                abort_unless($user->role === 'admin', 403);
                $created = $workflow->invoice((int) $this->validate($input, ['appointment_id' => 'bail|required|integer|min:1|exists:appointments,id'])['appointment_id']);
                break;
            case 'payments':
                $payment = $this->validate($input, ['invoice_id' => 'bail|required|integer|min:1|exists:invoices,id', 'amount' => 'required|numeric|decimal:0,2|min:0.01|max:99999999.99', 'method' => ['required', Rule::in(['cash', 'bank_transfer', 'card'])], 'reference' => 'nullable|string|max:255|required_unless:method,cash', 'paid_at' => 'required|date|before_or_equal:now', 'request_key' => 'required|string|uuid']);
                $created = $workflow->payment($user, ['invoice_id' => $payment['invoice_id'], 'amount' => $payment['amount'], 'method' => $payment['method'], 'paid_at' => $payment['paid_at'], 'request_key' => $payment['request_key']] + $payment);
                break;
            case 'settings':
                $data = $this->validate($input, ['name' => 'required|string|max:100', 'phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:1000']);
                DB::table('clinic_settings')->updateOrInsert(['id' => 1], $data + ['updated_at' => now()]);
                break;
            default: abort(404);
        }

        return $created;
    }

    /** @param array<string, mixed> $input */
    public function update(User $user, string $section, int $id, array $input): ?string
    {
        app(ClinicAccess::class)->authorize($user, $section);
        $workflow = app(ClinicWorkflow::class);
        switch ($section) {
            case 'owners':
            case 'veterinarians':
                $role = $section === 'owners' ? 'owner' : 'veterinarian';
                $profile = ($section === 'owners' ? Owner::query() : Veterinarian::query())
                    ->whereHas('user', fn ($q) => $q->where('role', $role))->findOrFail($id);

                return $this->update($user, 'users', $profile->user_id, array_replace($input, ['role' => $role]));
            case 'appointments':
                if (($input['operation'] ?? null) === 'reschedule') {
                    $schedule = $this->validate($input, ['veterinarian_id' => 'bail|required|integer|min:1|exists:veterinarians,id', 'appointment_date' => 'required|date|after:now']);
                    $workflow->reschedule($user, $id, ['veterinarian_id' => $schedule['veterinarian_id'], 'appointment_date' => $schedule['appointment_date']]);
                    break;
                }
                $status = $this->validate($input, ['status' => ['required', Rule::in(['confirmed', 'cancelled'])]])['status'];
                $workflow->status($user, $id, $status);
                break;
            case 'pets':
                abort_unless(in_array($user->role, ['owner', 'admin']), 403);
                $pet = Pet::findOrFail($id);
                abort_unless($user->role === 'admin' || $pet->owner->user_id === $user->id, 403);
                $pet->update($this->validate($input, $this->petRules()));
                break;
            case 'services':
                Service::findOrFail($id)->update($this->validate($input, $this->serviceRules() + ['active' => 'required|boolean']));
                break;
            case 'inventory':
                if (($input['operation'] ?? null) === 'stock') {
                    $stock = $this->validate($input, ['quantity_change' => 'required|integer|not_in:0|min:-1000000|max:1000000', 'notes' => 'required|string|max:1000']);
                    $workflow->adjustStock($user, $id, ['quantity_change' => $stock['quantity_change'], 'notes' => $stock['notes']]);
                } else {
                    Medicine::findOrFail($id)->update($this->validate($input, $this->medicineRules() + ['active' => 'required|boolean']));
                } break;
            case 'users':
                $target = User::findOrFail($id);
                $data = $this->validate($input, ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', new UniqueEmail($target->id)], 'active' => 'required|boolean', 'role' => ['sometimes', 'string', Rule::in(['admin', 'veterinarian', 'owner'])], 'phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:1000', 'license_number' => ['nullable', 'string', 'max:100', Rule::unique('veterinarians', 'license_number')->ignore($target->veterinarian?->id)], 'specialization' => 'nullable|string|max:100']);
                $updated = app(ClinicAccounts::class)->update($user, $target, ['name' => $data['name'], 'email' => $data['email'], 'active' => $data['active']] + $data);
                if ($target->id === $user->id && $updated->role !== 'admin') {
                    return route('dashboard');
                }
                break;
            default: abort(404);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function petRules(): array
    {
        return ['name' => 'required|string|max:100', 'species' => 'required|string|max:100', 'breed' => 'nullable|string|max:100', 'birth_date' => 'nullable|date|before_or_equal:today', 'sex' => ['required', Rule::in(['Male', 'Female', 'Unknown'])], 'weight' => 'nullable|numeric|decimal:0,2|min:0.01|max:999999.99', 'color' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:5000'];
    }

    /** @return array<string, mixed> */
    private function serviceRules(): array
    {
        return ['name' => 'required|string|max:100', 'price' => 'required|numeric|decimal:0,2|min:0|max:99999999.99', 'description' => 'nullable|string|max:5000'];
    }

    /** @return array<string, mixed> */
    private function medicineRules(): array
    {
        return ['name' => 'required|string|max:100', 'unit' => 'required|string|max:40', 'unit_price' => 'required|numeric|decimal:0,2|min:0|max:10000', 'reorder_level' => 'required|integer|min:0|max:1000000', 'expires_at' => 'required|date'];
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules)->validate();
    }
}
