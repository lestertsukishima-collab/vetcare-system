<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_and_rescheduling_normalize_offset_timestamps_before_conflict_checks(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
        $pet = Pet::factory()->create();
        $vet = User::factory()->veterinarian()->create();
        $service = Service::factory()->create();
        $data = ['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'appointment_date' => '2026-10-20T02:00:00.123Z', 'reason' => 'Offset booking'];
        $this->actingAs($pet->owner->user)->postJson('/api/v1/appointments', $data)->assertOk();
        $visit = Appointment::firstOrFail();
        $this->assertSame('2026-10-20 10:00:00', $visit->appointment_date->format('Y-m-d H:i:s'));
        $this->postJson('/api/v1/appointments', array_replace($data, ['appointment_date' => '2026-10-20T10:00:00+08:00']))->assertUnprocessable()->assertJsonValidationErrors('appointment_date');
        $this->patchJson('/api/v1/appointments/'.$visit->id, ['operation' => 'reschedule', 'veterinarian_id' => $vet->veterinarian->id, 'appointment_date' => '2026-10-21T03:00:00Z'])->assertOk();
        $this->assertSame('2026-10-21 11:00:00', $visit->fresh()->appointment_date->format('Y-m-d H:i:s'));
    }

    public function test_payment_retries_accept_the_same_instant_with_offsets_and_fractional_seconds(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
        $admin = User::factory()->admin()->create();
        $invoice = Invoice::factory()->create(['total' => 500]);
        $data = ['invoice_id' => $invoice->id, 'amount' => '100.00', 'method' => 'cash', 'paid_at' => '2026-10-19T02:00:00.123Z', 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson('/api/v1/payments', $data)->assertOk();
        $this->assertSame('2026-10-19 10:00:00', Payment::firstOrFail()->paid_at->format('Y-m-d H:i:s'));
        $this->postJson('/api/v1/payments', $data)->assertOk();
        $this->postJson('/api/v1/payments', array_replace($data, ['paid_at' => '2026-10-19T10:00:00+08:00']))->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(40000, $invoice->fresh()->balanceCents());
    }

    public function test_admin_created_mixed_case_email_can_log_in_and_duplicates_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/v1/owners', ['name' => 'Case Owner', 'email' => 'Case.Owner@Example.test', 'password' => 'review-password-123', 'role' => 'owner'])->assertOk();
        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', ['email' => 'case.owner@example.test', 'password' => 'review-password-123'])->assertSessionHasNoErrors();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'owner');
        $this->flushSession();
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Duplicate', 'email' => 'CASE.OWNER@EXAMPLE.TEST', 'password' => 'review-password-123', 'role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_legacy_email_casing_does_not_break_login_or_uniqueness(): void
    {
        $legacy = User::factory()->create();
        DB::table('users')->where('id', $legacy->id)->update(['email' => 'Legacy.Owner@Example.test']);
        $this->post('/login', ['email' => 'legacy.owner@example.test', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($legacy);
        $this->post('/logout');
        $this->post('/register', ['name' => 'Duplicate', 'email' => 'LEGACY.OWNER@EXAMPLE.TEST', 'password' => 'review-password-123', 'password_confirmation' => 'review-password-123'])->assertSessionHasErrors('email');
        $other = User::factory()->create();
        Livewire::actingAs($other)->test('pages::settings.profile')->set('email', 'legacy.owner@example.test')->call('updateProfileInformation')->assertHasErrors('email');
    }

    public function test_changed_email_casing_can_still_log_in_after_profile_update(): void
    {
        $owner = User::factory()->create();
        Livewire::actingAs($owner)->test('pages::settings.profile')->set('email', 'Updated.Owner@Example.test')->call('updateProfileInformation')->assertHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => 'updated.owner@example.test', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_initial_admin_command_supports_mixed_case_email(): void
    {
        $this->artisan('clinic:admin')->expectsQuestion('Full name', 'Review Admin')
            ->expectsQuestion('Email address', 'Review.Admin@Example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'review-password-123')->assertSuccessful();
        $this->post('/login', ['email' => 'review.admin@example.test', 'password' => 'review-password-123'])->assertSessionHasNoErrors();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_password_reset_uses_case_insensitive_lookup_for_legacy_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['email' => 'Legacy.Reset@Example.test']);
        $user->refresh();
        $this->post('/forgot-password', ['email' => 'legacy.reset@example.test'])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) {
            $this->post('/reset-password', ['token' => $notification->token, 'email' => 'LEGACY.RESET@EXAMPLE.TEST', 'password' => 'changed-review-password', 'password_confirmation' => 'changed-review-password'])->assertSessionHasNoErrors();

            return true;
        });
        $this->post('/login', ['email' => 'legacy.reset@example.test', 'password' => 'changed-review-password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_api_creates_return_resource_ids_for_the_complete_clinical_workflow(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $ownerId = $this->postJson('/api/v1/owners', ['name' => 'Workflow Owner', 'email' => 'workflow.owner@example.test', 'password' => 'review-password-123', 'role' => 'owner'])->assertOk()->assertJsonStructure(['data' => ['id', 'user_id']])->json('data.id');
        $vetId = $this->postJson('/api/v1/veterinarians', ['name' => 'Workflow Vet', 'email' => 'workflow.vet@example.test', 'password' => 'review-password-123', 'role' => 'veterinarian'])->assertOk()->assertJsonStructure(['data' => ['id', 'user_id']])->json('data.id');
        $serviceId = $this->postJson('/api/v1/services', ['name' => 'Workflow Consultation', 'price' => '500.00'])->assertOk()->json('data.id');
        $medicineId = $this->postJson('/api/v1/inventory', ['name' => 'Workflow Batch', 'unit' => 'tablet', 'unit_price' => '15.00', 'quantity' => 10, 'reorder_level' => 2, 'expires_at' => today()->addYear()->toDateString()])->assertOk()->json('data.id');
        $owner = User::where('email', 'workflow.owner@example.test')->firstOrFail();
        $this->assertSame($owner->owner->id, $ownerId);
        $this->flushSession();
        $this->actingAs($owner);
        $petId = $this->postJson('/api/v1/pets', ['name' => 'Workflow Patient', 'species' => 'Dog', 'sex' => 'Male'])->assertOk()->json('data.id');
        $visitId = $this->postJson('/api/v1/appointments', ['pet_id' => $petId, 'veterinarian_id' => $vetId, 'service_id' => $serviceId, 'appointment_date' => now()->addDay()->toIso8601String(), 'reason' => 'Full workflow'])->assertOk()->json('data.id');
        $vet = User::where('email', 'workflow.vet@example.test')->firstOrFail();
        $this->flushSession();
        $this->actingAs($vet)->patchJson('/api/v1/appointments/'.$visitId, ['status' => 'confirmed'])->assertOk();
        $recordId = $this->postJson('/api/v1/records', ['appointment_id' => $visitId, 'symptoms' => 'Review symptoms', 'diagnosis' => 'Review diagnosis', 'treatment' => 'Review treatment', 'items' => [['medicine_id' => $medicineId, 'quantity' => 2, 'dosage' => 'Clinician instructions', 'dispense_now' => true]], 'vaccinations' => [['vaccine_name' => 'Review vaccine', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addYear()->toDateString()]]])->assertOk()->json('data.id');
        $invoice = Invoice::where('appointment_id', $visitId)->firstOrFail();
        $this->assertSame(53000, $invoice->balanceCents());
        $this->assertSame(8, Medicine::findOrFail($medicineId)->quantity);
        $this->flushSession();
        $this->actingAs($admin)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => '530.00', 'method' => 'cash', 'paid_at' => now()->toIso8601String(), 'request_key' => (string) Str::uuid()])->assertOk()->assertJsonStructure(['data' => ['id']]);
        $this->flushSession();
        $this->actingAs($owner)->getJson('/api/v1/records/'.$recordId)->assertOk()->assertJsonPath('data.prescription.items.0.dispensed_quantity', 2)->assertJsonPath('data.vaccinations.0.vaccine_name', 'Review vaccine');
        $this->getJson('/api/v1/prescriptions')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/vaccinations?pet_id='.$petId)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/invoices/'.$invoice->id)->assertOk()->assertJsonPath('data.payments.0.amount', '530.00');
        $this->assertSame(0, $invoice->fresh()->balanceCents());
    }

    public function test_session_login_csrf_writes_and_logout_work_together_for_the_api(): void
    {
        $owner = User::factory()->create();
        $this->app['env'] = 'local';
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->post('/login', ['email' => $owner->email, 'password' => 'password', '_token' => $token])->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $owner->id);
        $this->postJson('/api/v1/pets', ['name' => 'Session patient', 'species' => 'Cat', 'sex' => 'Female'], ['X-CSRF-TOKEN' => session()->token()])->assertOk();
        $pet = Pet::where('name', 'Session patient')->firstOrFail();
        $this->deleteJson('/api/v1/pets/'.$pet->id)->assertStatus(419);
        $this->assertModelExists($pet);
        $this->deleteJson('/api/v1/pets/'.$pet->id, [], ['X-CSRF-TOKEN' => session()->token()])->assertOk();
        $this->post('/logout', ['_token' => session()->token()])->assertRedirect('/');
        Auth::forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_api_remains_inaccessible_until_two_factor_authentication_completes(): void
    {
        $owner = User::factory()->withTwoFactor()->create();
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/pets', ['name' => 'Blocked', 'species' => 'Cat', 'sex' => 'Female'])->assertUnauthorized();
        $this->assertDatabaseCount('pets', 0);
    }

    public function test_api_rate_limit_is_enforced_per_authenticated_account(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/me')->assertOk();
        }
        $this->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me')->assertOk();
    }

    public function test_failure_after_first_dispense_rolls_back_all_clinical_and_stock_writes(): void
    {
        $visit = Appointment::factory()->confirmed()->create();
        $first = Medicine::factory()->create(['quantity' => 10, 'unit_price' => 5]);
        $second = Medicine::factory()->create(['quantity' => 1, 'unit_price' => 10]);
        $data = ['appointment_id' => $visit->id, 'symptoms' => 'Symptoms', 'diagnosis' => 'Diagnosis', 'treatment' => 'Treatment', 'items' => [
            ['medicine_id' => $first->id, 'quantity' => 2, 'dosage' => 'First directions'],
            ['medicine_id' => $second->id, 'quantity' => 2, 'dosage' => 'Second directions'],
        ], 'vaccinations' => [['vaccine_name' => 'Review vaccine', 'date_given' => today()->toDateString()]]];
        $this->actingAs($visit->veterinarian->user)->postJson('/api/v1/records', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        foreach (['medical_records', 'prescriptions', 'prescription_items', 'vaccinations', 'invoices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertSame(10, $first->fresh()->quantity);
        $this->assertSame(1, $second->fresh()->quantity);
        $this->assertSame('confirmed', $visit->fresh()->status);
        $data['items'][1]['quantity'] = 1;
        $this->postJson('/api/v1/records', $data)->assertOk();
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 4);
        $this->assertSame(8, $first->fresh()->quantity);
        $this->assertSame(0, $second->fresh()->quantity);
        $this->assertSame(Invoice::cents($visit->service_fee) + 2000, $visit->fresh()->invoice->balanceCents());
    }
}
