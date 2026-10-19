<?php

namespace Tests\Feature;

use App\Livewire\Clinic\ActionForm;
use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OwnerJourneyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function visitPaths(): array
    {
        return [
            'admin confirms a prescription visit' => ['admin', 'prescription'],
            'admin confirms a vaccination visit' => ['admin', 'vaccination'],
            'assigned veterinarian confirms a prescription visit' => ['veterinarian', 'prescription'],
            'assigned veterinarian confirms a vaccination visit' => ['veterinarian', 'vaccination'],
        ];
    }

    #[DataProvider('visitPaths')]
    public function test_registered_owner_can_complete_the_clinic_journey_and_view_updated_history(string $confirmingRole, string $care): void
    {
        $this->skipUnlessFortifyHas(Features::registration());
        $this->skipUnlessFortifyHas(Features::emailVerification());
        Notification::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $vet = User::factory()->create(['role' => 'veterinarian']);
        $service = Service::factory()->create(['name' => 'Journey consultation', 'price' => '500.00']);
        $medicine = Medicine::factory()->create(['name' => 'Journey medicine', 'quantity' => 10]);

        $this->post(route('register.store'), [
            'name' => 'Journey Owner',
            'email' => 'journey.owner@example.test',
            'password' => 'Journey-password-2026!',
            'password_confirmation' => 'Journey-password-2026!',
            'phone' => '09123456789',
            'address' => 'Journey test address',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $owner = User::where('email', 'journey.owner@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('owner', $owner->role);
        $this->assertFalse($owner->hasVerifiedEmail());
        Notification::assertSentTo($owner, VerifyEmail::class);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $owner->id, 'hash' => sha1($owner->email),
        ]);
        $this->get($verificationUrl)->assertRedirect();
        $this->assertTrue($owner->refresh()->hasVerifiedEmail());

        Livewire::actingAs($owner)->test(ActionForm::class, ['section' => 'pets'])
            ->set('data.name', 'Journey Companion')
            ->set('data.species', 'Cat')
            ->set('data.sex', 'Female')
            ->call('save')->assertHasNoErrors()->assertDispatched('clinic-saved');

        $pet = Pet::where('owner_id', $owner->owner->id)->sole();
        Livewire::test(ActionForm::class, ['section' => 'appointments'])
            ->set('data.pet_id', (string) $pet->id)
            ->set('data.veterinarian_id', (string) $vet->veterinarian->id)
            ->set('data.service_id', (string) $service->id)
            ->set('data.appointment_date', now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'))
            ->set('data.reason', 'Journey consultation request')
            ->call('save')->assertHasNoErrors()->assertDispatched('clinic-saved');

        $visit = Appointment::where('pet_id', $pet->id)->sole();
        $this->assertSame('pending', $visit->status);
        $confirmer = $confirmingRole === 'admin' ? $admin : $vet;
        Livewire::actingAs($confirmer)->test(Workspace::class, ['section' => 'appointments'])
            ->call('changeStatus', $visit->id, 'confirmed')->assertHasNoErrors();
        $this->assertSame('confirmed', $visit->refresh()->status);
        $this->travelTo($visit->appointment_date);

        Livewire::actingAs($vet)->test(ActionForm::class, ['section' => 'records'])
            ->set('data.appointment_id', (string) $visit->id)
            ->set('data.symptoms', 'Journey examination findings')
            ->set('data.diagnosis', 'Journey assessment')
            ->set('data.treatment', 'Journey care plan')
            ->set('data.notes', 'Journey follow-up notes')
            ->set('data.instructions', $care === 'prescription' ? 'Recorded clinician instructions' : '')
            ->set('data.items', $care === 'prescription' ? [[
                'medicine_id' => (string) $medicine->id, 'quantity' => 2,
                'dosage' => 'Recorded clinician dosage', 'dispense_now' => '0',
            ]] : [])
            ->set('data.vaccinations', $care === 'vaccination' ? [[
                'vaccine_name' => 'Journey preventive dose',
                'date_given' => today()->toDateString(),
                'next_due_date' => today()->addYear()->toDateString(),
                'batch_number' => 'JOURNEY-001',
            ]] : [])
            ->call('save')->assertHasNoErrors()->assertDispatched('clinic-saved');

        $this->assertSame('completed', $visit->refresh()->status);
        $record = $visit->medicalRecord()->firstOrFail();
        $this->assertSame($pet->id, $record->pet_id);
        $this->assertSame($vet->veterinarian->id, $record->veterinarian_id);
        $this->assertSame('Journey assessment', $record->diagnosis);
        $this->assertSame('Journey care plan', $record->treatment);

        $history = $this->actingAs($owner)->get(route('clinic.index', ['section' => 'records', 'patient' => $pet->id]))
            ->assertOk()->assertSee('Journey assessment')->assertSee('Journey care plan')->assertSee('Journey examination findings');
        $this->get(route('clinic.index', 'appointments'))->assertOk()->assertSee('completed');

        if ($care === 'prescription') {
            $this->assertNotNull($record->prescription);
            $this->assertSame($medicine->id, $record->prescription->items()->sole()->medicine_id);
            $this->assertSame(0, $record->vaccinations()->count());
            $history->assertSee('Recorded clinician dosage');
            $this->get(route('clinic.index', 'prescriptions'))->assertOk()->assertSee('Recorded clinician instructions');
        } else {
            $this->assertNull($record->prescription);
            $this->assertSame('Journey preventive dose', $record->vaccinations()->sole()->vaccine_name);
            $history->assertSee('Journey preventive dose');
            $this->get(route('clinic.index', 'vaccinations'))->assertOk()->assertSee('Journey preventive dose');
        }
    }
}
