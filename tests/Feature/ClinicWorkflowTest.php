<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function visit(?User $owner = null, ?User $vet = null): Appointment
    {
        $owner ??= $this->account();
        $vet ??= $this->account('veterinarian');
        $pet = $owner->owner->pets()->create(['name' => 'Milo', 'species' => 'Cat', 'sex' => 'Male']);
        $service = Service::create(['name' => 'Consultation', 'price' => 500]);

        return Appointment::create(['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'service_fee' => 500, 'appointment_date' => now()->addDay(), 'status' => 'confirmed', 'reason' => 'Routine visit']);
    }

    public function test_registration_always_creates_an_owner_profile(): void
    {
        $user = app(CreateNewUser::class)->create(['name' => 'New Owner', 'email' => 'new@example.test', 'password' => 'A-secure-password-123!', 'password_confirmation' => 'A-secure-password-123!', 'role' => 'admin']);
        $this->assertSame('owner', $user->role);
        $this->assertDatabaseHas('owners', ['user_id' => $user->id]);
    }

    public function test_owners_cannot_manage_staff_or_view_other_pets(): void
    {
        $this->account('admin'); // Profile IDs must not be mistaken for user IDs.
        $owner = $this->account();
        $other = $this->account();
        $this->actingAs($owner)->post('/clinic/pets', ['name' => 'Secret Pet', 'species' => 'Cat', 'sex' => 'Female', 'owner_id' => $other->owner->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pets', ['name' => 'Secret Pet', 'owner_id' => $owner->owner->id]);
        $this->actingAs($other)->get('/clinic/pets')->assertDontSee('Secret Pet');
        $this->get('/clinic/users')->assertForbidden();
        $this->post('/clinic/services', ['name' => 'Exam', 'price' => 500])->assertForbidden();
        $pet = Pet::first();
        $this->patch('/clinic/pets/'.$pet->id, ['name' => 'Stolen', 'species' => 'Cat', 'sex' => 'Male'])->assertForbidden();
    }

    public function test_booking_checks_ownership_overlaps_and_fee_snapshot(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $vet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        $data = ['pet_id' => $visit->pet_id, 'veterinarian_id' => $visit->veterinarian_id, 'service_id' => $visit->service_id, 'appointment_date' => $visit->appointment_date->copy()->addMinutes(15)->format('Y-m-d H:i:s'), 'reason' => 'Checkup'];
        $this->actingAs($other)->post('/clinic/appointments', $data)->assertForbidden();
        $this->actingAs($owner)->post('/clinic/appointments', $data)->assertSessionHasErrors('appointment_date');
        $data['appointment_date'] = $visit->appointment_date->copy()->addMinutes(30)->format('Y-m-d H:i:s');
        $this->post('/clinic/appointments', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('appointments', ['status' => 'pending', 'service_fee' => 500]);
        $this->patch('/clinic/appointments/'.$visit->id, ['status' => 'confirmed'])->assertForbidden();
        $this->patch('/clinic/appointments/'.$visit->id, ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $data['appointment_date'] = $visit->appointment_date->format('Y-m-d H:i:s');
        $this->post('/clinic/appointments', $data)->assertSessionHasNoErrors();
    }

    public function test_examination_dispenses_items_records_vaccination_and_invoices_atomically(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $otherVet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        $medicine = Medicine::create(['name' => 'Example medicine', 'quantity' => 20, 'reorder_level' => 5, 'expires_at' => today()->addYear(), 'unit' => 'tablet', 'unit_price' => 12.50]);
        $data = ['appointment_id' => $visit->id, 'symptoms' => 'Recorded findings', 'diagnosis' => 'Clinical assessment', 'treatment' => 'Care plan', 'instructions' => 'As prescribed', 'items' => [['medicine_id' => $medicine->id, 'quantity' => 3, 'dosage' => 'Clinician instructions']], 'vaccinations' => [['vaccine_name' => 'Recorded vaccine', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addYear()->toDateString(), 'batch_number' => 'LOT-01']]];
        $this->actingAs($otherVet)->post('/clinic/records', $data)->assertNotFound();
        $this->actingAs($vet)->post('/clinic/records', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('medicines', ['id' => $medicine->id, 'quantity' => 17]);
        $this->assertDatabaseHas('prescription_items', ['medicine_id' => $medicine->id, 'quantity' => 3, 'unit_price' => 12.50]);
        $this->assertDatabaseHas('inventory_transactions', ['medicine_id' => $medicine->id, 'quantity_change' => -3, 'balance_after' => 17, 'type' => 'dispensed']);
        $this->assertDatabaseHas('vaccinations', ['vaccine_name' => 'Recorded vaccine', 'batch_number' => 'LOT-01']);
        $this->assertDatabaseHas('invoices', ['appointment_id' => $visit->id, 'service_amount' => 500, 'medicine_amount' => 37.50, 'total' => 537.50]);
        $this->assertSame('completed', $visit->fresh()->status);
        $this->post('/clinic/records', $data)->assertSessionHasErrors('appointment_id');
        $this->assertDatabaseCount('medical_records', 1);
        $this->actingAs($owner)->get('/clinic/records')->assertSee('Clinical assessment');
        $this->get('/clinic/prescriptions')->assertSee('Clinician instructions');
        $this->get('/clinic/vaccinations')->assertSee('LOT-01');
        $this->get('/clinic/invoices')->assertSee('537.50');
        $this->actingAs($this->account())->get('/clinic/records')->assertDontSee('Clinical assessment');
        $this->get('/clinic/invoices')->assertDontSee('537.50');
    }

    public function test_insufficient_or_expired_stock_rolls_back_clinical_record(): void
    {
        $vet = $this->account('veterinarian');
        $visit = $this->visit(vet: $vet);
        $medicine = Medicine::create(['name' => 'Low stock', 'quantity' => 1, 'expires_at' => today()->addYear(), 'unit_price' => 5]);
        $data = ['appointment_id' => $visit->id, 'symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Plan', 'items' => [['medicine_id' => $medicine->id, 'quantity' => 2, 'dosage' => 'Instructions']]];
        $this->actingAs($vet)->post('/clinic/records', $data)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('medical_records', 0);
        $this->assertDatabaseCount('prescriptions', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame('confirmed', $visit->fresh()->status);
        $medicine->update(['quantity' => 10, 'expires_at' => today()->subDay()]);
        $this->post('/clinic/records', $data)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('medical_records', 0);
    }

    public function test_payment_balance_and_stock_adjustments_cannot_go_negative(): void
    {
        $admin = $this->account('admin');
        $visit = $this->visit();
        $visit->update(['status' => 'completed']);
        $this->actingAs($admin)->post('/clinic/invoices', ['appointment_id' => $visit->id])->assertSessionHasNoErrors();
        $invoice = Invoice::first();
        $data = ['invoice_id' => $invoice->id, 'amount' => '200.25', 'method' => 'cash', 'paid_at' => now()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()];
        $this->post('/clinic/payments', $data)->assertSessionHasNoErrors();
        $this->assertSame(29975, $invoice->fresh()->balanceCents());
        $data['amount'] = '300';
        $data['request_key'] = (string) Str::uuid();
        $this->post('/clinic/payments', $data)->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 1);
        $medicine = Medicine::create(['name' => 'Stock test', 'quantity' => 5, 'expires_at' => today()->addYear()]);
        $stock = ['operation' => 'stock', 'quantity_change' => -6, 'notes' => 'Stock count correction'];
        $this->patch('/clinic/inventory/'.$medicine->id, $stock)->assertSessionHasErrors('quantity_change');
        $this->assertSame(5, $medicine->fresh()->quantity);
        $stock['quantity_change'] = 4;
        $this->patch('/clinic/inventory/'.$medicine->id, $stock)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_transactions', ['medicine_id' => $medicine->id, 'quantity_change' => 4, 'balance_after' => 9]);
    }

    public function test_admin_can_create_profiles_and_all_pages_render(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->post('/clinic/veterinarians', ['name' => 'Dr. Test', 'email' => 'vet@example.test', 'password' => 'secure-staff-password', 'role' => 'veterinarian', 'license_number' => 'VET-001', 'specialization' => 'General practice'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('veterinarians', ['license_number' => 'VET-001']);
        foreach (['pets', 'appointments', 'records', 'vaccinations', 'prescriptions', 'owners', 'veterinarians', 'services', 'inventory', 'transactions', 'users', 'reports', 'settings', 'payments', 'invoices'] as $page) {
            $this->get('/clinic/'.$page)->assertOk();
        }
        $owner = $this->account();
        $this->patch('/clinic/users/'.$owner->id, ['name' => $owner->name, 'email' => $owner->email, 'active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'active' => false]);
        $this->actingAs($owner->fresh())->get('/dashboard')->assertForbidden();
    }
}
