<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ClinicOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function visit(?User $vet = null): Appointment
    {
        $vet ??= $this->account('veterinarian');
        $pet = $this->account()->owner->pets()->create(['name' => 'Operations Pet', 'species' => 'Dog', 'sex' => 'Unknown']);
        $service = Service::create(['name' => 'Consultation', 'price' => 500]);

        return Appointment::create(['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'service_fee' => 500, 'service_name' => 'Consultation', 'appointment_date' => now()->addDay(), 'status' => 'confirmed', 'reason' => 'Operations visit']);
    }

    public function test_prescribing_without_dispensing_does_not_require_stock_or_add_medicine_charges(): void
    {
        $vet = $this->account('veterinarian');
        $visit = $this->visit($vet);
        $medicine = Medicine::create(['name' => 'Prescription medicine', 'unit' => 'tablet', 'unit_price' => 25, 'quantity' => 0, 'expires_at' => today()->addYear()]);
        $this->actingAs($vet)->post('/clinic/records', ['appointment_id' => $visit->id, 'symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Plan', 'items' => [['medicine_id' => $medicine->id, 'quantity' => 5, 'dosage' => 'Clinician directions', 'dispense_now' => 0]]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('prescription_items', ['medicine_id' => $medicine->id, 'quantity' => 5, 'dispensed_quantity' => 0]);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseHas('invoices', ['appointment_id' => $visit->id, 'medicine_amount' => 0, 'total' => 500]);
        $this->get('/clinic/prescriptions')->assertSee('Not dispensed');
    }

    public function test_retried_payment_is_recorded_once_and_changed_retries_are_rejected(): void
    {
        $admin = $this->account('admin');
        $visit = $this->visit();
        $visit->update(['status' => 'completed']);
        $invoice = Invoice::create(['appointment_id' => $visit->id, 'service_amount' => 500, 'medicine_amount' => 0, 'total' => 500, 'due_date' => today()->addWeek()]);
        $data = ['invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'cash', 'paid_at' => now()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->post('/clinic/payments', $data)->assertSessionHasNoErrors();
        $this->post('/clinic/payments', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(40000, $invoice->fresh()->balanceCents());
        $data['amount'] = 120;
        $this->post('/clinic/payments', $data)->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_rescheduling_checks_conflicts_and_preserves_the_original_service_fee(): void
    {
        $visit = $this->visit();
        $otherVet = $this->account('veterinarian');
        $occupied = $this->visit($otherVet);
        $owner = $visit->pet->owner->user;
        $data = ['operation' => 'reschedule', 'veterinarian_id' => $otherVet->veterinarian->id, 'appointment_date' => $occupied->appointment_date->format('Y-m-d H:i:s')];
        $this->actingAs($owner)->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasErrors('appointment_date');
        $data['appointment_date'] = $occupied->appointment_date->copy()->addMinutes(30)->format('Y-m-d H:i:s');
        $visit->service->update(['price' => 999]);
        $this->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointments', ['id' => $visit->id, 'veterinarian_id' => $otherVet->veterinarian->id, 'service_fee' => 500, 'status' => 'pending']);
        $this->actingAs($this->account())->patch('/clinic/appointments/'.$visit->id, $data)->assertNotFound();
        $visit->update(['status' => 'completed']);
        $this->actingAs($owner)->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasErrors('appointment_id');
    }

    public function test_admin_can_change_roles_without_losing_existing_profiles_or_pet_links(): void
    {
        $admin = $this->account('admin');
        $owner = $this->account();
        $ownerProfileId = $owner->owner->id;
        $pet = $owner->owner->pets()->create(['name' => 'Retained Pet', 'species' => 'Cat', 'sex' => 'Female']);
        $data = ['name' => $owner->name, 'email' => $owner->email, 'active' => 1, 'role' => 'veterinarian'];
        $this->actingAs($admin)->patch('/clinic/users/'.$owner->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('veterinarian', $owner->fresh()->role);
        $this->assertDatabaseHas('veterinarians', ['user_id' => $owner->id]);
        $data['role'] = 'owner';
        $this->patch('/clinic/users/'.$owner->id, $data)->assertSessionHasNoErrors();
        $this->assertSame($ownerProfileId, $owner->fresh()->owner->id);
        $this->assertDatabaseHas('pets', ['id' => $pet->id, 'owner_id' => $ownerProfileId]);
    }

    public function test_last_admin_is_retained_and_vets_with_open_visits_cannot_be_removed(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->patch('/clinic/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'active' => 1, 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);
        $vet = $this->account('veterinarian');
        $this->visit($vet);
        $this->patch('/clinic/users/'.$vet->id, ['name' => $vet->name, 'email' => $vet->email, 'active' => 0, 'role' => 'veterinarian'])->assertSessionHasErrors('active');
        $this->assertTrue($vet->fresh()->active);
    }

    public function test_array_ids_and_non_numeric_route_ids_are_rejected_without_server_errors(): void
    {
        $visit = $this->visit();
        $owner = $visit->pet->owner->user;
        $data = ['pet_id' => [$visit->pet_id], 'veterinarian_id' => $visit->veterinarian_id, 'service_id' => $visit->service_id, 'appointment_date' => now()->addDays(2)->format('Y-m-d H:i:s'), 'reason' => 'Invalid identifier'];
        $this->actingAs($owner)->postJson('/clinic/appointments', $data)->assertUnprocessable()->assertJsonValidationErrors('pet_id');
        $this->patch('/clinic/appointments/not-a-number', ['status' => 'cancelled'])->assertNotFound();
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_appointment_changes_enforce_actor_and_account_permissions(): void
    {
        $admin = $this->account('admin');
        $vet = $this->account('veterinarian');
        $visit = $this->visit($vet);
        $data = ['operation' => 'reschedule', 'veterinarian_id' => $visit->veterinarian_id, 'appointment_date' => $visit->appointment_date->format('Y-m-d H:i:s')];
        $this->actingAs($vet)->patch('/clinic/appointments/'.$visit->id, $data)->assertForbidden();
        $this->actingAs($visit->pet->owner->user)->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $visit->fresh()->status);
        $inactive = $this->account('veterinarian');
        $inactive->forceFill(['active' => false])->save();
        $data['veterinarian_id'] = $inactive->veterinarian->id;
        $this->actingAs($admin)->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasErrors('veterinarian_id');
        $visit->update(['appointment_date' => now()->subDay()]);
        $data['veterinarian_id'] = $vet->veterinarian->id;
        $data['appointment_date'] = now()->addDays(2)->format('Y-m-d H:i:s');
        $this->actingAs($visit->pet->owner->user)->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasErrors('appointment_date');
        $this->actingAs($admin)->patch('/clinic/appointments/'.$visit->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('pending', $visit->fresh()->status);
    }

    public function test_form_validation_recovers_from_malformed_nested_values(): void
    {
        $vet = $this->account('veterinarian');
        $visit = $this->visit($vet);
        $medicine = Medicine::create(['name' => 'Input test', 'quantity' => 10, 'expires_at' => today()->addYear()]);
        $data = ['_form_key' => 'create-records', 'appointment_id' => $visit->id, 'symptoms' => ['invalid'], 'diagnosis' => 'Assessment', 'treatment' => 'Plan', 'items' => [['medicine_id' => $medicine->id, 'quantity' => [2], 'dosage' => ['invalid']]]];
        $this->actingAs($vet)->from('/clinic/records')->post('/clinic/records', $data)->assertSessionHasErrors(['symptoms', 'items.0.quantity', 'items.0.dosage']);
        $this->get('/clinic/records')->assertOk();
        $this->assertDatabaseCount('medical_records', 0);
    }

    public function test_former_admin_can_change_role_but_cannot_delete_payment_audit_history(): void
    {
        $admin = $this->account('admin');
        $this->account('admin');
        $visit = $this->visit();
        $invoice = Invoice::create(['appointment_id' => $visit->id, 'service_amount' => 500, 'medicine_amount' => 0, 'total' => 500, 'due_date' => today()->addWeek()]);
        $this->actingAs($admin)->post('/clinic/payments', ['invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'cash', 'paid_at' => now()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->patch('/clinic/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'active' => 1, 'role' => 'owner'])->assertRedirect(route('dashboard'));
        $this->actingAs($admin->fresh())->get('/clinic/users')->assertForbidden();
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasErrors('password');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('payments', ['recorded_by' => $admin->id, 'amount' => 100]);
    }

    public function test_upgrade_preserves_existing_dispensed_quantities_and_payment_receipts(): void
    {
        $admin = $this->account('admin');
        $vet = $this->account('veterinarian');
        $visit = $this->visit($vet);
        $medicine = Medicine::create(['name' => 'Legacy supply', 'quantity' => 10, 'unit_price' => 5, 'expires_at' => today()->addYear()]);
        $this->actingAs($vet)->post('/clinic/records', ['appointment_id' => $visit->id, 'symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Plan', 'items' => [['medicine_id' => $medicine->id, 'quantity' => 2, 'dosage' => 'Directions']]])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->actingAs($admin)->post('/clinic/payments', ['invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'cash', 'paid_at' => now()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $migration = require database_path('migrations/2026_10_17_000004_track_dispensing_and_payment_retries.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('prescription_items', ['medicine_id' => $medicine->id, 'quantity' => 2, 'dispensed_quantity' => 2]);
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'amount' => 100, 'request_key' => null]);
        $this->assertSame(41000, $invoice->fresh()->balanceCents());
    }
}
