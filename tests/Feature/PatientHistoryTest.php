<?php

namespace Tests\Feature;

use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\PatientHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PatientHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00', config('app.timezone')));
    }

    private function record(Pet $pet, User $vet, string $date, string $diagnosis = 'Routine assessment', string $treatment = 'Follow-up plan'): MedicalRecord
    {
        $visit = Appointment::factory()->for($pet)->for($vet->veterinarian)->completed()->create(['appointment_date' => $date.' 09:00:00']);

        return MedicalRecord::factory()->for($visit)->create(['diagnosis' => $diagnosis, 'treatment' => $treatment, 'created_at' => now()]);
    }

    public function test_bella_has_the_supplied_profile_and_a_timeline_ordered_by_actual_care_dates(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['name' => 'Bella', 'breed' => 'Golden Retriever', 'sex' => 'Female', 'birth_date' => '2023-04-10']);
        $this->record($pet, $vet, '2026-03-22', 'Ear infection', 'Ear medication');
        $recent = $this->record($pet, $vet, '2026-10-10', 'Skin allergy', 'Medication prescribed');
        Vaccination::factory()->for($recent, 'medicalRecord')->create(['vaccine_name' => 'Anti-Rabies', 'date_given' => '2026-07-03', 'next_due_date' => '2027-07-03']);

        Livewire::actingAs($vet)->test(Workspace::class, ['section' => 'records', 'patient' => (string) $pet->id])
            ->assertSee('Bella')->assertSee('Golden Retriever')->assertSee('Female')->assertSee('3 years old')
            ->assertSeeInOrder(['Skin allergy', 'Medication prescribed', 'Vaccination: Anti-Rabies', 'Ear infection', 'Ear medication'])
            ->assertSee('Oct 10, 2026')->assertSee('Jul 03, 2026')->assertSee('Mar 22, 2026')
            ->assertViewHas('patientVisitCount', 2)->assertViewHas('patientVaccinationCount', 1)
            ->assertViewHas('patientTimeline', fn ($events) => $events->pluck('date')->map->toDateString()->all() === ['2026-10-10', '2026-07-03', '2026-03-22']);
        $this->actingAs($vet)->get('/clinic/records?patient='.$pet->id)->assertOk()->assertSee('3 years old');
        $this->get('/clinic/pets')->assertSee('patient='.$pet->id, false)->assertSee('View medical history');
        $assignment = Appointment::factory()->for($pet)->for($vet->veterinarian)->confirmed()->create();
        $this->get('/clinic/appointments')->assertSee('Medical history')->assertSee('patient='.$pet->id, false);
        $this->get('/clinic/records?patient='.$pet->id.'&appointment='.$assignment->id)->assertOk()->assertSee('Skin allergy')->assertSee('name="appointment_id"', false);
    }

    public function test_search_finds_patient_profiles_and_clinical_details_then_opens_the_complete_history(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Alex Santos']);
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['name' => 'Bella', 'breed' => 'Golden Retriever']);
        $record = $this->record($pet, $vet, '2026-10-10', 'Skin allergy', 'Medication prescribed');
        $this->record($pet, $vet, '2026-03-22', 'Ear infection', 'Ear medication');
        Vaccination::factory()->for($record, 'medicalRecord')->create(['vaccine_name' => 'Anti-Rabies', 'date_given' => '2026-07-03', 'next_due_date' => '2027-07-03']);
        $rx = Prescription::factory()->for($record, 'medicalRecord')->create();
        PrescriptionItem::factory()->for($rx)->create(['medicine_name' => 'Original sample medicine', 'dosage' => 'Recorded directions']);
        $screen = Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'records']);
        foreach (['bella', 'GOLDEN RETRIEVER', 'Alex Santos', 'skin allergy', 'Ear medication', 'Original sample medicine', 'Anti-Rabies', '2026-07-03'] as $query) {
            $screen->set('search', $query)->assertViewHas('historyPatients', fn ($patients) => $patients->sole()->id === $pet->id);
        }
        $screen->call('selectPatient', (string) $pet->id)->assertSet('search', '')->assertViewHas('totalResults', 3)
            ->set('search', 'skin allergy')->assertSee('Skin allergy')->assertDontSee('Ear infection')->assertViewHas('totalResults', 1)
            ->set('search', 'ANTI-RABIES')->assertSee('Vaccination: Anti-Rabies')->assertDontSee('Skin allergy')->assertViewHas('totalResults', 1)
            ->set('search', '2026-07-03')->assertViewHas('totalResults', 1)
            ->set('search', 'Original sample medicine')->assertSee('Recorded directions')->assertViewHas('totalResults', 1)
            ->set('search', 'not a clinical detail')->assertSee('No matching history.')
            ->call('clearFilters')->assertSet('patient', (string) $pet->id)->assertViewHas('totalResults', 3);
    }

    public function test_patient_history_is_scoped_to_owners_and_assigned_veterinarians(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $priorVet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['name' => 'Visible patient']);
        $private = Pet::factory()->create(['name' => 'Private patient']);
        $this->record($pet, $priorVet, '2026-07-03', 'Prior veterinarian findings');
        $this->record($private, $priorVet, '2026-07-03', 'Private clinical findings');
        Appointment::factory()->for($pet)->for($vet->veterinarian)->confirmed()->create();

        foreach ([$owner, $vet] as $user) {
            $this->actingAs($user)->get('/clinic/records?patient='.$pet->id)->assertOk()->assertSee('Prior veterinarian findings')->assertDontSee('Private clinical findings');
            $this->get('/clinic/records?patient='.$private->id)->assertNotFound();
            Livewire::actingAs($user)->test(Workspace::class, ['section' => 'records'])->set('search', 'Private patient')->assertViewHas('historyPatients', fn ($patients) => $patients->isEmpty())->assertDontSee('Private clinical findings');
            Livewire::test(Workspace::class, ['section' => 'records'])->call('selectPatient', (string) $private->id)->assertNotFound();
        }
        $this->actingAs(User::factory()->admin()->create())->get('/clinic/records?patient='.$private->id)->assertOk()->assertSee('Private clinical findings');
    }

    public function test_malformed_or_missing_patient_ids_return_controlled_errors(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get('/clinic/records?patient[id]=1')->assertStatus(400);
        $this->get('/clinic/records?patient='.str_repeat('9', 19))->assertStatus(400);
        foreach (['0', '-1', 'bad-id', '1.0', '999999'] as $id) {
            $this->get('/clinic/records?patient='.$id)->assertNotFound();
        }
        $this->get('/clinic/records?patient=')->assertOk();
    }

    public function test_timeline_search_runs_before_pagination_and_patient_changes_reset_the_view(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $first = Pet::factory()->for($owner->owner)->create(['name' => 'Bella']);
        $second = Pet::factory()->for($owner->owner)->create(['name' => 'Bella']);
        foreach (range(1, 14) as $offset) {
            $this->record($first, $vet, today()->subDays($offset)->toDateString(), 'Visit '.$offset);
        }
        $this->record($second, $vet, '2026-09-10', 'Other Bella findings');

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'records', 'patient' => (string) $first->id])
            ->assertViewHas('totalResults', 14)->assertViewHas('patientTimeline', fn ($events) => $events->count() === 6)
            ->assertDontSee('Visit 14')->call('nextPage')->assertDontSee('Visit 14')->assertViewHas('patientTimeline', fn ($events) => $events->count() === 6)
            ->call('nextPage')->assertSee('Visit 14')->assertViewHas('patientTimeline', fn ($events) => $events->count() === 2)
            ->set('search', 'Visit 14')->assertSet('paginators.page', 1)->assertViewHas('totalResults', 1)->assertSee('Visit 14')
            ->call('selectPatient', (string) $second->id)->assertSet('search', '')->assertSet('paginators.page', 1)
            ->assertViewHas('selectedPatient', fn ($pet) => $pet->id === $second->id)->assertSee('Other Bella findings')->assertDontSee('Visit 14')
            ->call('clearPatient')->assertSet('patient', '')->assertViewHas('selectedPatient', null)->assertSee('Recent medical records');
    }

    public function test_patients_without_records_have_a_profile_and_an_honest_empty_state(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['birth_date' => null, 'breed' => null, 'weight' => null, 'color' => null, 'notes' => null]);

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'records'])
            ->call('selectPatient', (string) $pet->id)->assertSee('Age unknown')->assertSee('Breed unspecified')->assertSee('No medical history yet.')
            ->assertViewHas('patientVisitCount', 0)->assertViewHas('patientVaccinationCount', 0)->assertViewHas('totalResults', 0);
    }

    public function test_age_is_calculated_at_the_clinic_date_in_years_months_or_days(): void
    {
        $history = app(PatientHistory::class);
        foreach (['2023-10-17' => '3 years old', '2023-10-18' => '2 years old', '2025-10-17' => '1 year old', '2026-07-17' => '3 months old', '2026-09-17' => '1 month old', '2026-10-16' => '1 day old', '2026-10-15' => '2 days old', '2026-10-17' => 'Born today', '2026-10-18' => 'Age unknown', '' => 'Age unknown'] as $birthDate => $expected) {
            $this->assertSame($expected, $history->age(new Pet(['birth_date' => $birthDate ?: null])));
        }
    }

    public function test_prescriptions_preserve_catalog_snapshots_and_clinical_text_is_escaped(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $record = $this->record($pet, $vet, '2026-10-10', '<script>alert("history")</script>');
        $rx = Prescription::factory()->for($record, 'medicalRecord')->create(['instructions' => 'Recorded prescription instructions']);
        $medicine = Medicine::factory()->create(['name' => 'Original medicine', 'unit' => 'tablet']);
        PrescriptionItem::factory()->for($rx)->for($medicine)->create(['quantity' => 3, 'dispensed_quantity' => 0, 'dosage' => 'Recorded directions']);
        $medicine->update(['name' => 'Renamed medicine', 'unit' => 'bottle']);

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'records', 'patient' => (string) $pet->id])
            ->assertSee('Original medicine')->assertSee('3 tablet')->assertDontSee('Renamed medicine')->assertSee('Not dispensed')
            ->assertSee('Recorded prescription instructions')->assertSee('Recorded directions')
            ->assertDontSee('<script>alert("history")</script>', false)->assertSee('&lt;script&gt;alert(&quot;history&quot;)&lt;/script&gt;', false);
    }

    public function test_saved_clinical_events_refresh_the_open_patient_timeline(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $screen = Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'records', 'patient' => (string) $pet->id])->assertSee('No medical history yet.');
        $this->record($pet, $vet, '2026-10-17', 'New assessment');

        $screen->dispatch('clinic-saved')->assertSee('New assessment')->assertDontSee('No medical history yet.')->assertViewHas('patientVisitCount', 1);
    }

    public function test_losing_patient_access_blocks_an_already_open_timeline(): void
    {
        $vet = User::factory()->veterinarian()->create();
        $otherVet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->create();
        $this->record($pet, $otherVet, '2026-07-03', 'Prior assessment');
        $assignment = Appointment::factory()->for($pet)->for($vet->veterinarian)->confirmed()->create();
        $screen = Livewire::actingAs($vet)->test(Workspace::class, ['section' => 'records', 'patient' => (string) $pet->id])->assertSee('Prior assessment');
        $assignment->update(['veterinarian_id' => $otherVet->veterinarian->id]);

        $screen->call('refreshWorkspace')->assertNotFound();
    }
}
