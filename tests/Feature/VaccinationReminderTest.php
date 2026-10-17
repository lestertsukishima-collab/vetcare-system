<?php

namespace Tests\Feature;

use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\WorkspaceData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class VaccinationReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00', config('app.timezone')));
    }

    private function dose(Pet $pet, ?string $due, string $name = 'Anti-Rabies', string $given = '2026-09-15', ?User $vet = null): Vaccination
    {
        $vet ??= User::factory()->veterinarian()->create();
        $visit = Appointment::factory()->for($pet)->for($vet->veterinarian)->completed()->create(['appointment_date' => $given.' 09:00:00']);
        $record = MedicalRecord::factory()->for($visit)->create();

        return Vaccination::factory()->for($record, 'medicalRecord')->create(['vaccine_name' => $name, 'date_given' => $given, 'next_due_date' => $due]);
    }

    public function test_the_supplied_bella_example_displays_full_dates_and_upcoming_status(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['name' => 'Bella']);
        $this->dose($pet, '2027-09-15');

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'vaccinations'])
            ->assertSee('Bella’s Anti-Rabies vaccination')->assertSee('Last vaccination')->assertSee('Next vaccination')
            ->assertSee('September 15, 2026')->assertSee('September 15, 2027')->assertSee('Upcoming')
            ->assertViewHas('vaccinationFollowUps', fn ($rows) => $rows->sole()['status'] === 'upcoming');
    }

    public function test_due_notifications_count_unique_pets_and_include_today_through_day_thirty(): void
    {
        $admin = User::factory()->admin()->create();
        $pets = Pet::factory()->count(6)->create();
        $this->dose($pets[0], today()->toDateString());
        $this->dose($pets[0], today()->addDays(30)->toDateString(), 'Sample booster');
        $this->dose($pets[1], today()->addDays(29)->toDateString());
        $this->dose($pets[2], today()->subDay()->toDateString());
        $this->dose($pets[3], today()->addDays(31)->toDateString());
        $this->dose($pets[4], null);
        $this->dose($pets[5], today()->subDay()->toDateString(), 'Anti-Rabies', '2025-09-15');
        $this->dose($pets[5], today()->addYear()->toDateString(), ' anti-rabies ', '2026-09-15');

        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'dashboard'])
            ->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['pets_due_soon'] === 2 && $alerts['pets_overdue'] === 1)
            ->assertSee('2 pets have vaccinations due within 30 days.')->assertSee('1 pet has overdue vaccinations.')
            ->assertSee('wire:poll.60s.visible', false)->assertSee('status=due_soon', false)->assertSee('status=overdue', false);
    }

    public function test_inventory_alerts_use_strict_minimums_and_today_counts_exclude_cancellations(): void
    {
        $admin = User::factory()->admin()->create();
        Medicine::factory()->create(['name' => 'Below threshold', 'quantity' => 4, 'reorder_level' => 5, 'active' => true]);
        Medicine::factory()->create(['name' => 'At threshold', 'quantity' => 5, 'reorder_level' => 5, 'active' => true]);
        Medicine::factory()->create(['name' => 'Inactive below', 'quantity' => 0, 'reorder_level' => 5, 'active' => false]);
        Medicine::factory()->create(['name' => 'Zero minimum', 'quantity' => 0, 'reorder_level' => 0, 'active' => true]);
        foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $index => $status) {
            Appointment::factory()->create(['status' => $status, 'appointment_date' => today()->setTime(9 + $index, 0)]);
        }
        Appointment::factory()->create(['appointment_date' => today()->subMinute()]);
        Appointment::factory()->create(['appointment_date' => today()->addDay()]);

        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'dashboard'])
            ->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['medicines_below_minimum'] === 1 && $alerts['appointments_today'] === 3)
            ->assertSee('1 medicine is below minimum stock.')->assertSee('3 appointments scheduled today.')
            ->assertSee('status=below_minimum', false)->assertSee('status=scheduled', false);
        Livewire::test(Workspace::class, ['section' => 'inventory'])->set('status', 'below_minimum')
            ->assertViewHas('totalResults', 1)->assertViewHas('medicines', fn ($rows) => $rows->sole()->name === 'Below threshold');
        Livewire::test(Workspace::class, ['section' => 'appointments'])->set('date', 'today')->set('status', 'scheduled')
            ->assertViewHas('totalResults', 3)->assertViewHas('appointments', fn ($rows) => ! $rows->contains('status', 'cancelled'));
    }

    public function test_owner_and_veterinarian_notifications_respect_patient_and_assignment_scopes(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $otherVet = User::factory()->veterinarian()->create();
        $pet = Pet::factory()->for($owner->owner)->create(['name' => 'Visible Bella']);
        $private = Pet::factory()->create(['name' => 'Private patient']);
        $this->dose($pet, today()->addDay()->toDateString(), vet: $otherVet);
        $this->dose($private, today()->addDay()->toDateString(), vet: $otherVet);
        Appointment::factory()->for($pet)->for($vet->veterinarian)->create(['appointment_date' => today()->setTime(11, 0)]);
        Appointment::factory()->for($private)->for($otherVet->veterinarian)->create(['appointment_date' => today()->setTime(12, 0)]);
        Medicine::factory()->create(['quantity' => 0, 'reorder_level' => 5]);

        foreach ([$owner, $vet] as $user) {
            Livewire::actingAs($user)->test(Workspace::class, ['section' => 'dashboard'])
                ->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['pets_due_soon'] === 1 && $alerts['appointments_today'] === 1 && $alerts['medicines_below_minimum'] === null)
                ->assertSee('Visible Bella')->assertDontSee('Private patient')->assertDontSee('below minimum stock.')
                ->assertSee('1 pet has vaccinations due within 30 days.')->assertSee('1 appointment scheduled today.');
        }
    }

    public function test_status_filters_match_current_doses_and_keep_the_full_history_available(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $todayDose = $this->dose($pet, today()->toDateString(), 'Due today vaccine');
        $soon = $this->dose($pet, today()->addDays(30)->toDateString(), 'Boundary vaccine');
        $overdue = $this->dose($pet, today()->subDay()->toDateString(), 'Missed vaccine');
        $future = $this->dose($pet, today()->addDays(31)->toDateString(), 'Future vaccine');
        $unscheduled = $this->dose($pet, null, 'Unscheduled vaccine');
        $this->dose($pet, today()->subDay()->toDateString(), 'Boundary vaccine', '2025-09-15');

        $screen = Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'vaccinations']);
        foreach (['due_soon' => [$todayDose->id, $soon->id], 'due_today' => [$todayDose->id], 'overdue' => [$overdue->id], 'upcoming' => [$future->id], 'unscheduled' => [$unscheduled->id], 'attention' => [$todayDose->id, $soon->id, $overdue->id]] as $status => $ids) {
            $screen->set('status', $status)
                ->assertViewHas('vaccinationFollowUps', fn ($rows) => $rows->pluck('vaccination.id')->sort()->values()->all() === collect($ids)->sort()->values()->all())
                ->assertViewHas('totalResults', count($ids));
        }
        $screen->set('status', 'due_soon')->assertSee('Boundary vaccine')->assertDontSee('Future vaccine')->assertDontSee('Historical dose')
            ->set('search', 'no such vaccine')->assertSee('No vaccination reminders match these filters.')
            ->call('clearFilters')->assertSee('Historical dose')->assertViewHas('totalResults', 6)->assertSet('status', '')->assertSet('search', '');
        $this->actingAs($owner)->get('/clinic/vaccinations?status=overdue')->assertOk()->assertSee('Missed vaccine')->assertDontSee('Future vaccine');
    }

    public function test_a_new_dose_removes_stale_notifications_on_livewire_refresh(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $this->dose($pet, today()->subDay()->toDateString(), given: '2025-09-15');
        $screen = Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'dashboard'])
            ->assertSee('1 pet has overdue vaccinations.');

        $this->dose($pet, null, given: '2026-10-17');
        $screen->dispatch('clinic-saved')->assertDontSee('1 pet has overdue vaccinations.')
            ->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['pets_due_soon'] === 0 && $alerts['pets_overdue'] === 0);
        $this->assertCount(1, app(WorkspaceData::class)->for($owner, 'vaccinations')['latestVaccinations']);
        $this->assertDatabaseCount('vaccinations', 2);
    }

    public function test_status_filter_only_shows_matching_vaccines_when_a_visit_has_multiple_doses(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $due = $this->dose($pet, today()->addDay()->toDateString(), 'Due booster');
        Vaccination::factory()->for($due->medicalRecord, 'medicalRecord')->create(['vaccine_name' => 'Later booster', 'date_given' => $due->date_given, 'next_due_date' => today()->addYear()]);

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'vaccinations'])
            ->set('status', 'due_soon')->assertViewHas('totalResults', 1)->assertSee('Due booster')->assertDontSee('Later booster')
            ->call('clearFilters')->assertSee('Due booster')->assertSee('Later booster');
    }

    public function test_today_rolls_over_in_the_configured_clinic_timezone(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $this->dose($pet, '2026-10-17');
        Appointment::factory()->for($pet)->create(['appointment_date' => '2026-10-17 23:59:00']);
        $screen = Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'dashboard'])
            ->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['pets_due_soon'] === 1 && $alerts['pets_overdue'] === 0 && $alerts['appointments_today'] === 1);

        $this->travelTo(Carbon::parse('2026-10-18 00:01:00', config('app.timezone')));
        $screen->call('refreshWorkspace')->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['pets_due_soon'] === 0 && $alerts['pets_overdue'] === 1 && $alerts['appointments_today'] === 0);
    }
}
