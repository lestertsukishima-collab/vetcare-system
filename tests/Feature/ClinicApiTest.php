<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_and_account_checks_return_json_without_accept_header(): void
    {
        $this->get('/api/v1/pets')->assertUnauthorized()->assertJsonStructure(['message']);
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/api/v1/pets')->assertForbidden();
        $user->forceFill(['email_verified_at' => now(), 'active' => false])->save();
        $this->get('/api/v1/me')->assertForbidden();
    }

    public function test_owner_lists_and_details_are_scoped_and_paginated(): void
    {
        $pet = Pet::factory()->create();
        $other = Pet::factory()->create();
        $owner = $pet->owner->user;
        $this->actingAs($owner)->getJson('/api/v1/pets?per_page=1')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $pet->id);
        $this->getJson('/api/v1/pets/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/inventory')->assertForbidden();
        $this->getJson('/api/v1/pets?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/pets?status[]=pending')->assertUnprocessable();
        $this->getJson('/api/v1/unknown')->assertNotFound();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $owner->id)->assertJsonMissingPath('data.password');
    }

    public function test_pet_writes_reuse_ownership_and_validation_rules(): void
    {
        $pet = Pet::factory()->create();
        $other = Pet::factory()->create();
        $data = ['name' => 'Milo', 'species' => 'Cat', 'sex' => 'Male', 'owner_id' => $other->owner_id];
        $this->actingAs($pet->owner->user)->postJson('/api/v1/pets', $data)->assertOk();
        $this->assertDatabaseHas('pets', ['name' => 'Milo', 'owner_id' => $pet->owner_id]);
        $this->patchJson('/api/v1/pets/'.$other->id, $data)->assertForbidden();
        $this->postJson('/api/v1/pets', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'species', 'sex']);
        $this->postJson('/api/v1/services', ['name' => 'Unauthorized', 'price' => 1])->assertForbidden();
        $this->deleteJson('/api/v1/pets/'.$pet->id)->assertOk();
    }

    public function test_clinical_history_is_visible_only_for_owned_or_assigned_patients(): void
    {
        $record = MedicalRecord::factory()->create();
        $other = MedicalRecord::factory()->create();
        $owner = $record->pet->owner->user;
        $this->actingAs($owner)->getJson('/api/v1/records')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/records/'.$other->id)->assertNotFound();
        $vet = User::factory()->create(['role' => 'veterinarian']);
        Appointment::factory()->create(['pet_id' => $record->pet_id, 'veterinarian_id' => $vet->veterinarian->id]);
        $this->actingAs($vet)->getJson('/api/v1/records/'.$record->id)->assertOk();
        $this->getJson('/api/v1/records/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/invoices')->assertForbidden();
    }

    public function test_api_booking_examination_and_payment_follow_existing_workflow(): void
    {
        $visit = Appointment::factory()->create();
        $owner = $visit->pet->owner->user;
        $this->actingAs($owner)->getJson('/api/v1/services')->assertOk();
        $this->getJson('/api/v1/veterinarians')->assertOk()->assertJsonMissingPath('data.0.user.email');
        $booking = $visit->only(['pet_id', 'veterinarian_id', 'service_id', 'reason']);
        $booking['appointment_date'] = $visit->appointment_date->toIso8601String();
        $this->postJson('/api/v1/appointments', $booking)->assertUnprocessable();
        $booking['appointment_date'] = $visit->appointment_date->copy()->addHour()->toIso8601String();
        $this->postJson('/api/v1/appointments', $booking)->assertOk();
        $vet = $visit->veterinarian->user;
        $this->actingAs($vet)->patchJson('/api/v1/appointments/'.$visit->id, ['status' => 'confirmed'])->assertOk();
        $this->postJson('/api/v1/records', ['appointment_id' => $visit->id, 'symptoms' => 'Symptoms', 'diagnosis' => 'Assessment', 'treatment' => 'Plan'])->assertOk();
        $invoice = $visit->fresh()->invoice;
        $admin = User::factory()->create(['role' => 'admin']);
        $payment = ['invoice_id' => $invoice->id, 'amount' => '0.01', 'method' => 'cash', 'paid_at' => now()->toIso8601String(), 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson('/api/v1/payments', $payment)->assertOk();
        $this->postJson('/api/v1/payments', $payment)->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->postJson('/api/v1/payments', array_replace($payment, ['amount' => '0.02']))->assertUnprocessable();
        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonStructure(['data' => ['appointments_today']]);
    }

    public function test_session_api_requires_csrf_tokens_on_writes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        // CSRF middleware normally bypasses verification in the testing environment.
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/pets', ['name' => 'Milo', 'species' => 'Cat', 'sex' => 'Male'])->assertStatus(419);
    }
}
