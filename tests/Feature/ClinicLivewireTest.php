<?php

namespace Tests\Feature;

use App\Livewire\Clinic\ActionForm;
use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Services\ClinicWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ClinicLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function visit(User $owner, User $vet): Appointment
    {
        $pet = $owner->owner->pets()->create(['name' => 'Milo', 'species' => 'Cat', 'sex' => 'Male']);
        $service = Service::create(['name' => 'Consultation', 'price' => 500]);

        return Appointment::create(['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'service_fee' => 500, 'service_name' => 'Consultation', 'appointment_date' => now()->addDay(), 'reason' => 'Routine care', 'status' => 'pending']);
    }

    public function test_live_search_is_scoped_and_resets_pagination(): void
    {
        $owner = $this->account();
        foreach (range(1, 15) as $n) {
            $owner->owner->pets()->create(['name' => sprintf('Companion %02d', $n), 'species' => 'Dog', 'sex' => 'Unknown']);
        }
        $this->account()->owner->pets()->create(['name' => 'Private companion', 'species' => 'Cat', 'sex' => 'Unknown']);
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'pets'])
            ->assertSee('Companion 01')->assertDontSee('Companion 15')->assertDontSee('Private companion')
            ->call('nextPage')->assertSee('Companion 07')->assertSee('Companion 12')->assertDontSee('Companion 15')->assertDontSee('Companion 01')
            ->call('nextPage')->assertSee('Companion 15')->assertDontSee('Companion 07')
            ->set('search', 'Companion 01')->assertSet('paginators.page', 1)->assertSee('Companion 01')->assertDontSee('Companion 02')
            ->set('search', 'Private companion')->assertDontSee('Private companion')->assertSee('No companions found.')
            ->call('clearFilters')->assertSet('search', '')->assertSee('Companion 01');
    }

    public function test_live_pet_forms_validate_create_and_update_without_changing_ownership(): void
    {
        $owner = $this->account();
        Livewire::actingAs($owner)->test(ActionForm::class, ['section' => 'pets'])
            ->set('data', ['name' => 'Luna', 'species' => 'Cat', 'sex' => 'Female', 'weight' => -1])
            ->call('save')->assertHasErrors('weight')
            ->set('data.weight', '3.50')->set('data.color', 'Black')->call('save')->assertHasNoErrors()->assertDispatched('clinic-saved');
        $pet = Pet::firstOrFail();
        $this->assertSame($owner->owner->id, $pet->owner_id);
        Livewire::test(ActionForm::class, ['section' => 'pets', 'recordId' => $pet->id, 'variant' => 'edit'])
            ->assertSet('data.weight', '3.50')->set('data.weight', '3.75')->set('data.notes', 'Recorded note')->call('save')->assertHasNoErrors();
        $this->assertSame('3.75', $pet->fresh()->weight);
        $this->assertSame('Recorded note', $pet->fresh()->notes);
        Livewire::actingAs($this->account())->test(ActionForm::class, ['section' => 'pets', 'recordId' => $pet->id, 'variant' => 'edit'])->assertForbidden();
    }

    public function test_status_actions_enforce_visibility_roles_and_valid_transitions(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'appointments'])->call('changeStatus', $visit->id, 'confirmed')->assertForbidden();
        Livewire::actingAs($this->account())->test(Workspace::class, ['section' => 'appointments'])->call('changeStatus', $visit->id, 'cancelled')->assertNotFound();
        Livewire::actingAs($vet)->test(Workspace::class, ['section' => 'appointments'])->call('changeStatus', $visit->id, 'confirmed')->assertHasNoErrors()->assertDispatched('clinic-toast')->assertSee('confirmed');
        $this->assertSame('confirmed', $visit->fresh()->status);
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'appointments'])->call('changeStatus', $visit->id, 'cancelled')->assertHasNoErrors();
        $this->assertSame('cancelled', $visit->fresh()->status);
    }

    public function test_booking_and_rescheduling_share_overlap_checks(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        $data = ['pet_id' => $visit->pet_id, 'veterinarian_id' => $visit->veterinarian_id, 'service_id' => $visit->service_id, 'appointment_date' => $visit->appointment_date->format('Y-m-d\TH:i'), 'reason' => 'Follow-up'];
        Livewire::actingAs($owner)->test(ActionForm::class, ['section' => 'appointments'])->set('data', $data)->call('save')->assertHasErrors('appointment_date')
            ->set('data.appointment_date', $visit->appointment_date->copy()->addHour()->format('Y-m-d\TH:i'))->call('save')->assertHasNoErrors()->assertDispatched('clinic-saved');
        $this->assertDatabaseCount('appointments', 2);
        Livewire::test(ActionForm::class, ['section' => 'appointments', 'recordId' => $visit->id, 'variant' => 'reschedule'])
            ->set('data.appointment_date', $visit->appointment_date->copy()->addHour()->format('Y-m-d\TH:i'))->call('save')->assertHasErrors('appointment_date')
            ->set('data.appointment_date', $visit->appointment_date->copy()->addDays(2)->format('Y-m-d\TH:i'))->call('save')->assertHasNoErrors();
        $this->assertTrue($visit->fresh()->appointment_date->isSameDay(now()->addDays(3)));
    }

    public function test_clinical_repeaters_retain_rows_and_complete_the_visit_atomically(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        $visit->update(['status' => 'confirmed']);
        $medicine = Medicine::create(['name' => 'Example medicine', 'quantity' => 5, 'unit' => 'tablet', 'unit_price' => 10, 'expires_at' => today()->addYear()]);
        $component = Livewire::actingAs($vet)->test(ActionForm::class, ['section' => 'records'])
            ->call('addRow', 'vaccinations')->set('data.vaccinations.1.vaccine_name', 'Recorded vaccine')->call('removeRow', 'vaccinations', 0)
            ->assertSet('data.vaccinations.1.vaccine_name', 'Recorded vaccine')
            ->set('data.appointment_id', $visit->id)->set('data.symptoms', 'Recorded symptoms')->set('data.diagnosis', 'Recorded diagnosis')->set('data.treatment', 'Recorded treatment')
            ->set('data.items.0', ['medicine_id' => $medicine->id, 'quantity' => 6, 'dosage' => 'Recorded dosage', 'dispense_now' => '1'])
            ->set('data.vaccinations.1.date_given', today()->toDateString())->call('save')->assertHasErrors('items');
        $this->assertDatabaseCount('medical_records', 0);
        $component->set('data.items.0.quantity', 2)->call('save')->assertHasNoErrors()->assertDispatched('clinic-saved');
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(3, $medicine->fresh()->quantity);
        $this->assertDatabaseHas('vaccinations', ['pet_id' => $visit->pet_id, 'vaccine_name' => 'Recorded vaccine']);
        $this->assertDatabaseHas('invoices', ['appointment_id' => $visit->id, 'total' => 520]);
    }

    public function test_payment_replay_remains_idempotent_through_livewire(): void
    {
        $admin = $this->account('admin');
        $visit = $this->visit($this->account(), $this->account('veterinarian'));
        $visit->update(['status' => 'completed']);
        $invoice = app(ClinicWorkflow::class)->invoice($visit->id);
        $data = ['invoice_id' => $invoice->id, 'amount' => '100.00', 'method' => 'cash', 'paid_at' => now()->format('Y-m-d\TH:i'), 'request_key' => (string) Str::uuid()];
        Livewire::actingAs($admin)->test(ActionForm::class, ['section' => 'payments'])->set('data', $data)->call('save')->assertHasNoErrors()->set('data', $data)->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(40000, Invoice::firstOrFail()->balanceCents());
    }

    public function test_filters_use_status_and_exact_dates_and_clear_together(): void
    {
        $owner = $this->account();
        $visit = $this->visit($owner, $this->account('veterinarian'));
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'appointments'])->set('status', 'confirmed')->assertDontSee('Routine care')
            ->set('status', 'pending')->set('date', today()->toDateString())->assertDontSee('Routine care')
            ->set('date', $visit->appointment_date->toDateString())->assertSee('Routine care')
            ->call('clearFilters')->assertSet('status', '')->assertSet('date', '')->assertSee('Routine care');
    }

    public function test_livewire_rechecks_admin_access_after_role_change(): void
    {
        $admin = $this->account('admin');
        $form = Livewire::actingAs($admin)->test(ActionForm::class, ['section' => 'services'])->set('data', ['name' => 'Unsafe service', 'price' => 100]);
        $admin->role = 'owner';
        $admin->save();
        $form->call('save')->assertForbidden();
        $this->assertDatabaseCount('services', 0);
    }

    public function test_prescription_filter_runs_before_pagination(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $withPrescription = $this->visit($owner, $vet);
        $record = $withPrescription->medicalRecord()->create(['symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Plan']);
        $record->prescription()->create(['instructions' => 'Keep this visible']);
        foreach (range(1, 13) as $offset) {
            $visit = $this->visit($owner, $vet);
            $visit->update(['appointment_date' => now()->addDays($offset + 2)]);
            $visit->medicalRecord()->create(['symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Plan']);
        }
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'prescriptions'])
            ->assertSee('Keep this visible')->assertViewHas('totalResults', 1);
    }

    public function test_admin_forms_create_services_adjust_stock_and_update_settings(): void
    {
        $admin = $this->account('admin');
        Livewire::actingAs($admin)->test(ActionForm::class, ['section' => 'services'])
            ->set('data', ['name' => 'Wellness visit', 'price' => '600.00', 'description' => 'Routine care'])->call('save')->assertHasNoErrors();
        $service = Service::firstOrFail();
        Livewire::test(ActionForm::class, ['section' => 'services', 'recordId' => $service->id, 'variant' => 'edit'])
            ->set('data.price', '650.00')->call('save')->assertHasNoErrors();
        $this->assertSame('650.00', $service->fresh()->price);
        $medicine = Medicine::create(['name' => 'Stock item', 'quantity' => 5, 'expires_at' => today()->addYear()]);
        Livewire::test(ActionForm::class, ['section' => 'inventory', 'recordId' => $medicine->id, 'variant' => 'stock'])
            ->set('data.quantity_change', -6)->set('data.notes', 'Stock reconciliation')->call('save')->assertHasErrors('quantity_change')
            ->set('data.quantity_change', 4)->call('save')->assertHasNoErrors();
        $this->assertSame(9, $medicine->fresh()->quantity);
        Livewire::test(ActionForm::class, ['section' => 'settings'])->set('data.name', 'Live Clinic')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('clinic_settings', ['name' => 'Live Clinic']);
    }
}
