<?php

namespace Tests\Feature;

use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClinicAuditTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function visit(User $vet, ?Pet $pet = null): Appointment
    {
        $pet ??= $this->account()->owner->pets()->create(['name' => 'Audit Pet', 'species' => 'Dog', 'sex' => 'Unknown']);
        $service = Service::create(['name' => 'Original consultation', 'price' => 400]);

        return Appointment::create(['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'service_fee' => 400, 'service_name' => $service->name, 'appointment_date' => now()->subHour(), 'status' => 'confirmed', 'reason' => 'Audit visit']);
    }

    private function symptoms(Appointment $visit): array
    {
        return ['appointment_id' => $visit->id, 'symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Care plan'];
    }

    public function test_inactive_accounts_cannot_log_in_or_continue_an_existing_session(): void
    {
        $user = $this->account();
        $user->forceFill(['active' => false])->save();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->forceFill(['active' => true])->save();
        $this->actingAs($user);
        User::whereKey($user->id)->update(['active' => false]);
        $this->get(route('profile.edit'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_blocked_account_deletion_preserves_records_and_the_session(): void
    {
        $owner = $this->account();
        $pet = $owner->owner->pets()->create(['name' => 'Keep Me', 'species' => 'Cat', 'sex' => 'Female']);
        $this->actingAs($owner);
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasErrors('password');
        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('pets', ['id' => $pet->id]);
        $this->assertDatabaseHas('owners', ['id' => $owner->owner->id]);
    }

    public function test_multiple_vaccinations_are_saved_with_dates_and_invalid_dates_roll_back(): void
    {
        $vet = $this->account('veterinarian');
        $visit = $this->visit($vet);
        $data = $this->symptoms($visit) + ['vaccinations' => [
            ['vaccine_name' => 'Vaccine A', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addYear()->toDateString(), 'batch_number' => 'BATCH-A'],
            ['vaccine_name' => 'Vaccine B', 'date_given' => today()->addDay()->toDateString(), 'next_due_date' => today()->addYear()->toDateString(), 'batch_number' => 'BATCH-B'],
        ]];
        $this->actingAs($vet)->post('/clinic/records', $data)->assertSessionHasErrors('vaccinations.1.date_given');
        $this->assertDatabaseCount('medical_records', 0);
        $data['vaccinations'][1]['date_given'] = today()->subDay()->toDateString();
        $data['vaccinations'][1]['next_due_date'] = today()->subDays(2)->toDateString();
        $this->post('/clinic/records', $data)->assertSessionHasErrors('vaccinations.1.next_due_date');
        $data['vaccinations'][1]['next_due_date'] = today()->addMonths(6)->toDateString();
        $this->post('/clinic/records', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('vaccinations', 2);
        $this->assertDatabaseHas('vaccinations', ['vaccine_name' => 'Vaccine B', 'batch_number' => 'BATCH-B']);
        $this->assertSame(today()->subDay()->toDateString(), Vaccination::where('vaccine_name', 'Vaccine B')->firstOrFail()->date_given->toDateString());
    }

    public function test_latest_vaccination_supersedes_older_follow_up_dates(): void
    {
        $vet = $this->account('veterinarian');
        $oldVisit = $this->visit($vet);
        $oldRecord = $oldVisit->medicalRecord()->create(['symptoms' => 'Old', 'diagnosis' => 'Old', 'treatment' => 'Old']);
        $oldRecord->vaccinations()->create(['vaccine_name' => 'Vaccine A', 'date_given' => today()->subYear(), 'next_due_date' => today()->subDay()]);
        $newVisit = $this->visit($vet, $oldVisit->pet);
        $newRecord = $newVisit->medicalRecord()->create(['symptoms' => 'New', 'diagnosis' => 'New', 'treatment' => 'New']);
        $newRecord->vaccinations()->create(['vaccine_name' => ' vaccine a ', 'date_given' => today(), 'next_due_date' => today()->addYear()]);
        Livewire::actingAs($vet)->test(Workspace::class, ['section' => 'vaccinations'])->assertViewHas('latestVaccinations', fn ($rows) => $rows->count() === 1 && $rows->first()['vaccination']->medical_record_id === $newRecord->id)->assertSee('Historical dose')->assertDontSee('class="badge overdue"', false);
    }

    public function test_assigned_vet_can_read_the_patients_prior_history_but_cannot_edit_other_visits(): void
    {
        $oldVet = $this->account('veterinarian');
        $newVet = $this->account('veterinarian');
        $visit = $this->visit($oldVet);
        $visit->medicalRecord()->create(['symptoms' => 'Prior clinical findings', 'diagnosis' => 'Prior assessment', 'treatment' => 'Prior plan']);
        $visit->update(['status' => 'completed']);
        $this->visit($newVet, $visit->pet);
        $unrelated = $this->visit($oldVet);
        $unrelated->medicalRecord()->create(['symptoms' => 'Unrelated private findings', 'diagnosis' => 'Private assessment', 'treatment' => 'Private plan']);
        $this->actingAs($newVet)->get('/clinic/records')->assertOk()->assertSee('Prior clinical findings')->assertDontSee('Unrelated private findings');
        Livewire::test(Workspace::class, ['section' => 'appointments'])->assertViewHas('appointments', fn ($rows) => ! $rows->contains('id', $visit->id));
        $this->post('/clinic/records', $this->symptoms($unrelated))->assertNotFound();
        $this->patch('/clinic/appointments/'.$unrelated->id, ['status' => 'cancelled'])->assertNotFound();
    }

    public function test_failed_submission_does_not_overwrite_other_pet_forms(): void
    {
        $owner = $this->account();
        $first = $owner->owner->pets()->create(['name' => 'First Pet', 'species' => 'Cat', 'sex' => 'Female']);
        $second = $owner->owner->pets()->create(['name' => 'Second Pet', 'species' => 'Dog', 'sex' => 'Male']);
        $this->actingAs($owner)->from('/clinic/pets')->patch('/clinic/pets/'.$first->id, ['_form_key' => 'update-pets-'.$first->id, 'name' => 'Attempted Rename', 'sex' => 'Unknown'])->assertSessionHasErrors('species');
        $html = $this->get('/clinic/pets')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $value = fn ($key) => $xpath->evaluate('string(//form[@data-form-key="'.$key.'"]//input[@name="name"]/@value)');
        $this->assertSame('Attempted Rename', $value('update-pets-'.$first->id));
        $this->assertSame('Second Pet', $value('update-pets-'.$second->id));
        $this->assertSame('', $value('create-pets'));
    }

    public function test_catalog_edits_do_not_rewrite_prescription_history_or_invoice_amounts(): void
    {
        $vet = $this->account('veterinarian');
        $visit = $this->visit($vet);
        $medicine = Medicine::create(['name' => 'Original medicine', 'unit' => 'tablet', 'unit_price' => 10, 'quantity' => 20, 'expires_at' => today()->addYear()]);
        $this->actingAs($vet)->post('/clinic/records', $this->symptoms($visit) + ['items' => [['medicine_id' => $medicine->id, 'quantity' => 2, 'dosage' => 'Recorded dosage']]])->assertSessionHasNoErrors();
        $medicine->update(['name' => 'Renamed medicine', 'unit' => 'bottle', 'unit_price' => 999]);
        $visit->service->update(['name' => 'Renamed service', 'price' => 999]);
        $this->get('/clinic/prescriptions')->assertSee('Original medicine')->assertSee('tablet')->assertDontSee('Renamed medicine')->assertSee('Original consultation');
        $this->assertDatabaseHas('invoices', ['appointment_id' => $visit->id, 'service_amount' => 400, 'medicine_amount' => 20, 'total' => 420]);
    }
}
