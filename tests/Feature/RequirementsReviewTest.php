<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Livewire\Clinic\ActionForm;
use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RequirementsReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_registration_collects_the_requested_contact_fields(): void
    {
        $user = app(CreateNewUser::class)->create(['name' => 'New Owner', 'email' => 'contact@example.test', 'password' => 'Sample-password-2026!', 'password_confirmation' => 'Sample-password-2026!', 'phone' => '09123456789', 'address' => '12 Example Street', 'role' => 'admin']);
        $this->assertSame('owner', $user->role);
        $this->assertSame('09123456789', $user->owner->phone);
        $this->assertSame('12 Example Street', $user->owner->address);
        $this->get('/register')->assertSee('name="phone"', false)->assertSee('name="address"', false);
    }

    public function test_owner_can_update_and_clear_only_their_own_contact_fields(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->owner->update(['phone' => '09000000001', 'address' => 'Original address']);
        $other = User::factory()->owner()->create();
        $other->owner->update(['phone' => '09000000002', 'address' => 'Other address']);
        $form = Livewire::actingAs($owner)->test('pages::settings.profile')
            ->assertSet('phone', '09000000001')->assertSet('address', 'Original address')
            ->set('phone', '09123456789')->set('address', ' Updated address ')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertSame('09123456789', $owner->owner->fresh()->phone);
        $this->assertSame('Updated address', $owner->owner->fresh()->address);
        $this->assertSame('Other address', $other->owner->fresh()->address);
        $form->set('phone', str_repeat('1', 51))->call('updateProfileInformation')->assertHasErrors('phone')
            ->set('phone', ' ')->set('address', ' ')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertNull($owner->owner->fresh()->phone);
        $this->assertNull($owner->owner->fresh()->address);
        $this->assertNotNull($owner->fresh()->email_verified_at);
    }

    public function test_directory_search_matches_values_rather_than_internal_json_keys(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Alex Santos']);
        Pet::factory()->for($owner->owner)->create(['name' => 'Luna', 'notes' => null]);
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'pets'])
            ->set('search', 'owner_id')->assertViewHas('totalResults', 0)
            ->set('search', 'Luna')->assertViewHas('totalResults', 1)
            ->set('search', 'Alex Santos')->assertViewHas('totalResults', 1);
    }

    public function test_vaccination_follow_up_search_and_history_stay_consistent(): void
    {
        $owner = User::factory()->owner()->create();
        $firstPet = Pet::factory()->for($owner->owner)->create(['name' => 'Luna']);
        $secondPet = Pet::factory()->for($owner->owner)->create(['name' => 'Milo']);
        foreach ([$firstPet, $secondPet] as $pet) {
            $visit = Appointment::factory()->for($pet)->completed()->create();
            $record = MedicalRecord::factory()->for($visit)->create();
            Vaccination::factory()->for($record, 'medicalRecord')->create();
        }
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'vaccinations'])
            ->set('search', 'Luna')->assertSee('Luna')->assertDontSee('Milo')->assertViewHas('totalResults', 1);
    }

    public function test_upcoming_filter_lists_the_nearest_visit_first(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $soon = Appointment::factory()->for($pet)->create(['appointment_date' => now()->addDay()]);
        $later = Appointment::factory()->for($pet)->create(['appointment_date' => now()->addMonth()]);
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'appointments'])
            ->set('date', 'upcoming')->assertViewHas('appointments', fn ($rows) => $rows->pluck('id')->all() === [$soon->id, $later->id]);
    }

    public function test_unverified_account_cannot_submit_an_already_open_livewire_form(): void
    {
        $owner = User::factory()->owner()->create();
        $form = Livewire::actingAs($owner)->test(ActionForm::class, ['section' => 'pets'])
            ->set('data', ['name' => 'Blocked pet', 'species' => 'Cat', 'sex' => 'Unknown']);
        $owner->forceFill(['email_verified_at' => null])->save();
        $form->call('save')->assertForbidden();
        $this->assertDatabaseCount('pets', 0);
    }

    public function test_malformed_livewire_repeaters_report_validation_errors(): void
    {
        $vet = User::factory()->veterinarian()->create();
        Livewire::actingAs($vet)->test(ActionForm::class, ['section' => 'records'])
            ->set('data.items', 'malformed')->call('addRow', 'items')->assertHasErrors('items');
        Livewire::test(ActionForm::class, ['section' => 'records'])
            ->set('data.vaccinations', 'malformed')->call('removeRow', 'vaccinations', 0)->assertHasErrors('vaccinations');
    }

    public function test_named_clinical_row_keys_are_rejected_without_saving_a_record(): void
    {
        $vet = User::factory()->veterinarian()->create();
        $visit = Appointment::factory()->for($vet->veterinarian)->confirmed()->create();
        $this->actingAs($vet)->post('/clinic/records', ['appointment_id' => $visit->id, 'symptoms' => 'Findings', 'diagnosis' => 'Assessment', 'treatment' => 'Plan', 'vaccinations' => ['unexpected-key' => ['vaccine_name' => 'Sample vaccine', 'date_given' => today()->toDateString()]]])
            ->assertSessionHasErrors('vaccinations');
        $this->assertDatabaseCount('medical_records', 0);
    }

    public function test_invalid_registration_contact_fields_do_not_break_the_returned_form(): void
    {
        $this->from('/register')->post('/register', ['name' => 'Owner', 'email' => 'bad-contact@example.test', 'password' => 'Sample-password-2026!', 'password_confirmation' => 'Sample-password-2026!', 'phone' => ['bad'], 'address' => ['bad']])
            ->assertSessionHasErrors(['phone', 'address']);
        $this->get('/register')->assertOk();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_former_owner_cannot_change_a_historical_owner_profile_from_staff_settings(): void
    {
        $user = User::factory()->owner()->create();
        $user->owner->update(['phone' => '09000000001', 'address' => 'Retained address']);
        $user->forceFill(['role' => 'veterinarian'])->save();
        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('phone', '09123456789')->set('address', 'Unexpected address')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertSame('Retained address', $user->owner->fresh()->address);
        $this->assertSame('09000000001', $user->owner->fresh()->phone);
    }

    public function test_invoice_search_accepts_the_displayed_invoice_number(): void
    {
        $owner = User::factory()->owner()->create();
        $pet = Pet::factory()->for($owner->owner)->create();
        $visit = Appointment::factory()->for($pet)->completed()->create();
        $invoice = Invoice::factory()->for($visit)->create();
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'invoices'])
            ->set('search', 'INV-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT))->assertViewHas('totalResults', 1);
    }

    public function test_malformed_workspace_query_parameters_return_a_controlled_error(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/clinic/pets?search[name]=Luna')->assertStatus(400);
        $this->get('/clinic/appointments?date[bad]=today')->assertStatus(400);
        $this->get('/clinic/pets?search=')->assertOk();
        Livewire::test(Workspace::class, ['section' => 'pets'])->set('search', str_repeat('x', 160))->assertSet('search', str_repeat('x', 150));
    }
}
