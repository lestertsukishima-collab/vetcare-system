<?php

namespace Tests\Feature;

use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\WorkspaceData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspacePaginationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function sections(): array
    {
        return [
            'pets' => ['pets', 'pets'],
            'appointments' => ['appointments', 'appointments'],
            'owners' => ['owners', 'owners'],
            'veterinarians' => ['veterinarians', 'vets'],
            'users' => ['users', 'users'],
            'services' => ['services', 'services'],
            'inventory' => ['inventory', 'medicines'],
            'invoices' => ['invoices', 'invoices'],
            'medical records' => ['records', 'history'],
            'prescriptions' => ['prescriptions', 'history'],
            'vaccinations' => ['vaccinations', 'history'],
            'payments' => ['payments', 'payments'],
            'stock history' => ['transactions', 'transactions'],
        ];
    }

    #[DataProvider('sections')]
    public function test_lists_advance_in_groups_of_six_without_skipping_or_repeating_records(string $section, string $key): void
    {
        $admin = User::factory()->admin()->create();
        match ($section) {
            'pets' => Pet::factory()->count(24)->create(),
            'appointments' => Appointment::factory()->count(24)->create(),
            'owners', 'users' => User::factory()->owner()->count(24)->create(),
            'veterinarians' => User::factory()->veterinarian()->count(24)->create(),
            'services' => Service::factory()->count(24)->create(),
            'inventory' => Medicine::factory()->count(24)->create(),
            'invoices' => Invoice::factory()->count(24)->create(),
            'records' => MedicalRecord::factory()->count(24)->create(),
            'prescriptions' => Prescription::factory()->count(24)->create(),
            'vaccinations' => Vaccination::factory()->count(24)->create(),
            'payments' => Payment::factory()->count(24)->create(),
            'transactions' => InventoryTransaction::factory()->count(24)->create(),
        };

        $expected = match ($section) {
            'payments' => Payment::latest()->orderByDesc('id')->pluck('id')->all(),
            'transactions' => InventoryTransaction::latest('id')->pluck('id')->all(),
            default => app(WorkspaceData::class)->for($admin, $section)[$key]->pluck('id')->all(),
        };
        $screen = Livewire::actingAs($admin)->test(Workspace::class, ['section' => $section]);
        foreach (array_chunk($expected, 6) as $index => $ids) {
            if ($index > 0) {
                $screen->call('nextPage');
            }
            $screen->assertViewHas($key, fn ($rows) => $rows->pluck('id')->all() === $ids)
                ->assertSee('Showing '.($index * 6 + 1).'–'.($index * 6 + count($ids)).' of '.count($expected));
        }
    }

    public function test_patient_search_cards_paginate_independently_and_search_resets_the_page(): void
    {
        $owner = User::factory()->owner()->create();
        $pets = Pet::factory()->for($owner->owner)->count(13)
            ->sequence(fn ($sequence) => ['name' => sprintf('Patient %02d', $sequence->index + 1)])
            ->create();

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'records'])
            ->assertViewHas('historyPatientPage', fn ($rows) => $rows->pluck('id')->all() === $pets->take(6)->pluck('id')->all())
            ->assertSeeHtml('aria-label="Patient results pagination"')
            ->call('nextPage', 'patientsPage')
            ->assertSet('paginators.page', 1)
            ->assertViewHas('historyPatientPage', fn ($rows) => $rows->pluck('id')->all() === $pets->slice(6, 6)->pluck('id')->all())
            ->call('nextPage', 'patientsPage')
            ->assertViewHas('historyPatientPage', fn ($rows) => $rows->sole()->id === $pets->last()->id)
            ->set('search', 'Patient 13')->assertSet('paginators.patientsPage', 1)
            ->assertViewHas('historyPatientPage', fn ($rows) => $rows->sole()->id === $pets->last()->id);

        Livewire::test(Workspace::class, ['section' => 'dashboard'])
            ->assertViewHas('pets', fn ($rows) => $rows->count() === 13);
    }

    public function test_vaccination_cards_have_their_own_pages_and_reminder_totals_stay_complete(): void
    {
        $pet = Pet::factory()->create();
        $visit = Appointment::factory()->for($pet)->completed()->create();
        $record = MedicalRecord::factory()->for($visit)->create();
        $doses = Vaccination::factory()->for($record, 'medicalRecord')->count(13)
            ->sequence(fn ($sequence) => [
                'vaccine_name' => sprintf('Pagination dose %02d', $sequence->index + 1),
                'date_given' => today()->subDay()->toDateString(),
                'next_due_date' => today()->addDays($sequence->index + 1)->toDateString(),
            ])->create();

        Livewire::actingAs($pet->owner->user)->test(Workspace::class, ['section' => 'vaccinations'])
            ->assertViewHas('vaccinationFollowUpPage', fn ($rows) => $rows->pluck('vaccination.id')->all() === $doses->take(6)->pluck('id')->all())
            ->assertSeeHtml('aria-label="Vaccination follow-ups pagination"')
            ->call('nextPage', 'vaccinationsPage')->assertSet('paginators.page', 1)
            ->assertViewHas('vaccinationFollowUpPage', fn ($rows) => $rows->pluck('vaccination.id')->all() === $doses->slice(6, 6)->pluck('id')->all())
            ->assertViewHas('latestVaccinations', fn ($rows) => $rows->count() === 13)
            ->assertViewHas('dashboardAlerts', fn ($alerts) => $alerts['pets_due_soon'] === 1)
            ->set('search', 'Pagination dose 13')->assertSet('paginators.vaccinationsPage', 1)
            ->assertViewHas('vaccinationFollowUpPage', fn ($rows) => $rows->sole()['vaccination']->id === $doses->last()->id);
    }

    public function test_an_out_of_range_directory_page_uses_the_last_available_page(): void
    {
        $owner = User::factory()->owner()->create();
        Pet::factory()->for($owner->owner)->count(13)->create();

        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'pets'])
            ->call('gotoPage', 99)->assertSet('paginators.page', 3)
            ->assertViewHas('pets', fn ($rows) => $rows->count() === 1)
            ->assertSee('Showing 13–13 of 13');
    }
}
