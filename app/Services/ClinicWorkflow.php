<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Veterinarian;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicWorkflow
{
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param array{pet_id: int|string, veterinarian_id: int|string, service_id: int|string, appointment_date: string, reason: string} $data */
    public function book(User $actor, array $data): Appointment
    {
        abort_unless(in_array($actor->role, ['admin', 'owner']), 403);

        return DB::transaction(function () use ($actor, $data) {
            // Serialize bookings for the same pet and veterinarian, including different owners.
            $pet = Pet::with('owner')->lockForUpdate()->findOrFail($data['pet_id']);
            abort_unless($actor->role === 'admin' || $pet->owner->user_id === $actor->id, 403);
            $vet = Veterinarian::with('user')->lockForUpdate()->findOrFail($data['veterinarian_id']);
            if (! $vet->user->active || $vet->user->role !== 'veterinarian') {
                $this->fail('veterinarian_id', 'Choose an active veterinarian.');
            }
            $service = Service::where('active', true)->findOrFail($data['service_id']);
            $at = Carbon::parse($data['appointment_date']);
            $this->ensureAvailable($pet->id, $vet->id, $at);

            return Appointment::create([
                'pet_id' => $pet->id, 'veterinarian_id' => $vet->id, 'service_id' => $service->id,
                'appointment_date' => $at, 'service_fee' => $service->price, 'service_name' => $service->name, 'status' => 'pending', 'reason' => $data['reason'],
            ]);
        });
    }

    private function ensureAvailable(int $petId, int $vetId, Carbon $at, ?int $except = null): void
    {
        $conflict = Appointment::where('status', '!=', 'cancelled')
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->where('appointment_date', '>', $at->copy()->subMinutes(30))
            ->where('appointment_date', '<', $at->copy()->addMinutes(30))
            ->where(fn ($q) => $q->where('veterinarian_id', $vetId)->orWhere('pet_id', $petId))->exists();
        if ($conflict) {
            $this->fail('appointment_date', 'The pet or veterinarian already has a visit within 30 minutes. Choose another slot.');
        }
    }

    /** @param array{veterinarian_id: int|string, appointment_date: string} $data */
    public function reschedule(User $actor, int $id, array $data): void
    {
        abort_unless(in_array($actor->role, ['admin', 'owner']), 403);
        DB::transaction(function () use ($actor, $id, $data) {
            $visible = Appointment::visibleTo($actor)->findOrFail($id);
            Pet::whereKey($visible->pet_id)->lockForUpdate()->firstOrFail();
            $vet = Veterinarian::with('user')->lockForUpdate()->findOrFail($data['veterinarian_id']);
            $appointment = Appointment::visibleTo($actor)->lockForUpdate()->findOrFail($id);
            if (! in_array($appointment->status, ['pending', 'confirmed']) || $appointment->medicalRecord()->exists()) {
                $this->fail('appointment_id', 'Only open appointments without a medical record can be rescheduled.');
            }
            if ($actor->role === 'owner' && $appointment->appointment_date->isPast()) {
                $this->fail('appointment_date', 'Contact the clinic to change a past appointment.');
            }
            if (! $vet->user->active || $vet->user->role !== 'veterinarian') {
                $this->fail('veterinarian_id', 'Choose an active veterinarian.');
            }
            $at = Carbon::parse($data['appointment_date']);
            $this->ensureAvailable($appointment->pet_id, $vet->id, $at, $appointment->id);
            if ($appointment->veterinarian_id === $vet->id && $appointment->appointment_date->eq($at)) {
                return;
            }
            $appointment->update(['veterinarian_id' => $vet->id, 'appointment_date' => $at, 'status' => 'pending']);
        });
    }

    public function status(User $actor, int $id, string $status): void
    {
        DB::transaction(function () use ($actor, $id, $status) {
            $a = Appointment::visibleTo($actor)->lockForUpdate()->findOrFail($id);
            abort_unless($actor->role !== 'owner' || $status === 'cancelled', 403);
            if (! in_array($a->status, ['pending', 'confirmed']) || ($a->status === 'confirmed' && $status === 'confirmed')) {
                $this->fail('status', 'This appointment cannot make that status change.');
            }
            if ($actor->role === 'owner' && $a->appointment_date->isPast()) {
                $this->fail('status', 'Contact the clinic to cancel a past appointment.');
            }
            $a->update(['status' => $status]);
        });
    }

    /**
     * @param  array{appointment_id: int|string, symptoms: string, diagnosis: string, treatment: string, notes?: ?string, instructions?: ?string, items?: ?array<array{medicine_id?: int|string|null, quantity?: int|string|null, dosage?: ?string, dispense_now?: bool|int|string}>, vaccinations?: ?array<array{vaccine_name?: ?string, date_given?: ?string, next_due_date?: ?string, batch_number?: ?string}>}  $data
     */
    public function examine(User $actor, array $data): MedicalRecord
    {
        return DB::transaction(function () use ($actor, $data) {
            $a = Appointment::visibleTo($actor)->lockForUpdate()->findOrFail($data['appointment_id']);
            abort_unless($actor->role === 'veterinarian', 403);
            if ($a->status !== 'confirmed' || $a->medicalRecord()->exists()) {
                $this->fail('appointment_id', 'Select a confirmed visit without an existing medical record.');
            }
            $record = $a->medicalRecord()->create(['pet_id' => $a->pet_id, 'veterinarian_id' => $a->veterinarian_id, 'symptoms' => $data['symptoms'], 'diagnosis' => $data['diagnosis'], 'treatment' => $data['treatment'], 'notes' => $data['notes'] ?? null]);
            $items = collect($data['items'] ?? [])->filter(fn ($i) => ! empty($i['medicine_id']))->sortBy('medicine_id');
            if ($items->isNotEmpty() || ! empty($data['instructions'])) {
                $rx = $record->prescription()->create(['instructions' => $data['instructions'] ?? null]);
                foreach ($items as $item) {
                    $quantity = (int) ($item['quantity'] ?? 0);
                    $dosage = $item['dosage'] ?? '';
                    if ($quantity < 1 || trim($dosage) === '') {
                        $this->fail('items', 'Each prescribed medicine needs a positive quantity and dosage instructions.');
                    }
                    $medicine = Medicine::lockForUpdate()->findOrFail($item['medicine_id']);
                    $dispensed = (bool) ($item['dispense_now'] ?? true) ? $quantity : 0;
                    if (! $medicine->active || ($dispensed > 0 && ($medicine->expires_at->lt(today()) || $medicine->quantity < $dispensed))) {
                        $this->fail('items', "{$medicine->name} is inactive, expired, or has insufficient stock.");
                    }
                    $line = $rx->items()->create(['medicine_id' => $medicine->id, 'medicine_name' => $medicine->name, 'medicine_unit' => $medicine->unit, 'quantity' => $quantity, 'dispensed_quantity' => $dispensed, 'dosage' => $dosage, 'unit_price' => $medicine->unit_price]);
                    if ($dispensed > 0) {
                        $medicine->decrement('quantity', $dispensed);
                        InventoryTransaction::create(['medicine_id' => $medicine->id, 'user_id' => $actor->id, 'prescription_item_id' => $line->id, 'quantity_change' => -$dispensed, 'balance_after' => $medicine->quantity, 'type' => 'dispensed', 'notes' => 'Dispensed with prescription #'.$rx->id]);
                    }
                }
            }
            foreach ($data['vaccinations'] ?? [] as $vaccination) {
                if (empty($vaccination['vaccine_name'])) {
                    continue;
                }
                $record->vaccinations()->create(['pet_id' => $a->pet_id, 'veterinarian_id' => $a->veterinarian_id, 'vaccine_name' => trim($vaccination['vaccine_name']), 'date_given' => $vaccination['date_given'] ?? today(), 'next_due_date' => $vaccination['next_due_date'] ?? null, 'batch_number' => $vaccination['batch_number'] ?? null]);
            }
            $a->update(['status' => 'completed']);
            $this->invoice($a->id);

            return $record;
        });
    }

    public function invoice(int $appointmentId): Invoice
    {
        return DB::transaction(function () use ($appointmentId) {
            $a = Appointment::with('medicalRecord.prescription.items')->lockForUpdate()->findOrFail($appointmentId);
            if ($a->status !== 'completed') {
                $this->fail('appointment_id', 'Only completed visits can be invoiced.');
            }
            if ($existing = $a->invoice()->first()) {
                return $existing;
            }
            $medicineCents = $a->medicalRecord?->prescription?->items->sum(fn ($i) => Invoice::cents($i->unit_price) * $i->dispensed_quantity) ?? 0;
            $serviceCents = Invoice::cents($a->service_fee);
            if ($serviceCents + $medicineCents > 9999999999) {
                $this->fail('items', 'The invoice total exceeds the supported amount. Reduce the dispensed quantities.');
            }

            return $a->invoice()->create(['service_amount' => $serviceCents / 100, 'medicine_amount' => $medicineCents / 100, 'total' => ($serviceCents + $medicineCents) / 100, 'due_date' => today()->addDays(7)]);
        });
    }

    /** @param array{invoice_id: int|string, amount: int|float|string, method: string, reference?: ?string, paid_at: string, request_key: string} $data */
    public function payment(User $actor, array $data): Payment
    {
        abort_unless($actor->role === 'admin', 403);

        return DB::transaction(function () use ($actor, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($data['invoice_id']);
            $existing = $invoice->payments()->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (Invoice::cents($existing->amount) !== Invoice::cents($data['amount']) || $existing->method !== $data['method'] ||
                    ($existing->reference ?? '') !== ($data['reference'] ?? '') || ! $existing->paid_at->eq(Carbon::parse($data['paid_at']))) {
                    $this->fail('request_key', 'This payment submission was already saved with different details. Reload the payment form.');
                }

                return $existing;
            }
            if (Invoice::cents($data['amount']) > $invoice->balanceCents()) {
                $this->fail('amount', 'Payment exceeds the remaining invoice balance.');
            }

            return $invoice->payments()->create(['amount' => $data['amount'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'paid_at' => $data['paid_at'], 'request_key' => $data['request_key'], 'recorded_by' => $actor->id]);
        });
    }

    /** @param array{quantity_change: int|string, notes: string} $data */
    public function adjustStock(User $actor, int $id, array $data): void
    {
        abort_unless($actor->role === 'admin', 403);
        DB::transaction(function () use ($actor, $id, $data) {
            $medicine = Medicine::lockForUpdate()->findOrFail($id);
            $change = (int) $data['quantity_change'];
            $balance = $medicine->quantity + $change;
            if ($balance < 0 || $balance > 1000000) {
                $this->fail('quantity_change', 'The resulting stock must be between 0 and 1,000,000.');
            }
            $medicine->update(['quantity' => $balance]);
            InventoryTransaction::create(['medicine_id' => $id, 'user_id' => $actor->id, 'quantity_change' => $change, 'balance_after' => $balance, 'type' => $change > 0 ? 'received' : 'adjustment', 'notes' => $data['notes']]);
        });
    }
}
