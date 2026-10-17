<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_roles_complete_a_visit_billing_and_inventory_workflow(): void
    {
        $admin = User::factory()->make();
        $admin->role = 'admin';
        $admin->save();
        $this->actingAs($admin)->post('/clinic/owners', ['name' => 'Lifecycle Owner', 'email' => 'owner@example.test', 'password' => 'secure-owner-password', 'role' => 'owner', 'phone' => '09123456789', 'address' => 'Clinic test address'])->assertSessionHasNoErrors();
        $this->post('/clinic/veterinarians', ['name' => 'Lifecycle Vet', 'email' => 'vet@example.test', 'password' => 'secure-veterinarian-password', 'role' => 'veterinarian', 'license_number' => 'LIFE-001'])->assertSessionHasNoErrors();
        $this->post('/clinic/services', ['name' => 'Lifecycle Consultation', 'price' => '500.00', 'description' => 'Clinic service'])->assertSessionHasNoErrors();
        $this->post('/clinic/inventory', ['name' => 'Lifecycle Medicine', 'quantity' => 20, 'unit' => 'tablet', 'unit_price' => '7.50', 'reorder_level' => 5, 'expires_at' => today()->addYear()->toDateString()])->assertSessionHasNoErrors();
        $this->post('/clinic/settings', ['name' => 'Lifecycle Clinic', 'phone' => '1234567', 'address' => 'Test clinic'])->assertSessionHasNoErrors();
        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $vet = User::where('email', 'vet@example.test')->firstOrFail();
        $medicine = Medicine::firstOrFail();
        $service = Service::firstOrFail();

        $this->actingAs($owner)->post('/clinic/pets', ['name' => 'Milo', 'species' => 'Cat', 'sex' => 'Male'])->assertSessionHasNoErrors();
        $this->post('/clinic/pets', ['name' => 'Luna', 'species' => 'Dog', 'sex' => 'Female'])->assertSessionHasNoErrors();
        $pet = Pet::where('name', 'Milo')->firstOrFail();
        $this->post('/clinic/appointments', ['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'appointment_date' => now()->addDay()->format('Y-m-d H:i:s'), 'reason' => 'Lifecycle visit'])->assertSessionHasNoErrors();
        $visit = Appointment::firstOrFail();
        $this->assertSame('pending', $visit->status);
        $this->assertSame(2, $owner->owner->pets()->count());
        $this->actingAs($admin)->patch('/clinic/appointments/'.$visit->id, ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->travelTo($visit->appointment_date);

        $this->actingAs($vet)->get('/clinic/appointments')->assertOk()->assertSee('Milo');
        $this->post('/clinic/records', [
            'appointment_id' => $visit->id, 'symptoms' => 'Lifecycle findings', 'diagnosis' => 'Lifecycle assessment', 'treatment' => 'Lifecycle plan',
            'instructions' => 'Lifecycle prescription instructions', 'items' => [['medicine_id' => $medicine->id, 'quantity' => 4, 'dosage' => 'Recorded clinician instructions']],
            'vaccinations' => [
                ['vaccine_name' => 'Lifecycle Vaccine A', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addYear()->toDateString(), 'batch_number' => 'A-001'],
                ['vaccine_name' => 'Lifecycle Vaccine B', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addMonths(6)->toDateString(), 'batch_number' => 'B-001'],
            ],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(53000, $invoice->balanceCents());
        $this->actingAs($owner)->get('/clinic/records')->assertSee('Lifecycle findings');
        $this->get('/clinic/prescriptions')->assertSee('Recorded clinician instructions');
        $this->get('/clinic/vaccinations')->assertSee('Lifecycle Vaccine A')->assertSee('Lifecycle Vaccine B');
        $this->get('/clinic/invoices')->assertSee('530.00');
        $this->post('/clinic/payments', [])->assertForbidden();

        $this->actingAs($admin)->post('/clinic/payments', ['invoice_id' => $invoice->id, 'amount' => '200.00', 'method' => 'cash', 'paid_at' => now()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(33000, $invoice->fresh()->balanceCents());
        $this->post('/clinic/payments', ['invoice_id' => $invoice->id, 'amount' => '330.00', 'method' => 'bank_transfer', 'reference' => 'PAY-LIFE-001', 'paid_at' => now()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(0, $invoice->fresh()->balanceCents());
        $this->patch('/clinic/inventory/'.$medicine->id, ['operation' => 'stock', 'quantity_change' => 5, 'notes' => 'Delivery LIFE-001'])->assertSessionHasNoErrors();
        $this->assertSame(21, $medicine->fresh()->quantity);
        $this->get('/clinic/reports')->assertOk()->assertSee('530.00');
        $this->actingAs($owner)->get('/clinic/invoices')->assertSee('Paid')->assertSee('PAY-LIFE-001');

        foreach (['users' => 3, 'owners' => 1, 'veterinarians' => 1, 'pets' => 2, 'appointments' => 1, 'medical_records' => 1, 'prescriptions' => 1, 'prescription_items' => 1, 'vaccinations' => 2, 'medicines' => 1, 'services' => 1, 'invoices' => 1, 'payments' => 2, 'inventory_transactions' => 3] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }
}
