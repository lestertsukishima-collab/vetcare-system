<?php

namespace Tests\Feature;

use App\Livewire\Clinic\Workspace;
use App\Models\Appointment;
use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ClinicCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_read_update_and_delete_an_unused_pet(): void
    {
        $owner = User::factory()->create();
        $data = ['name' => 'CRUD companion', 'species' => 'Cat', 'sex' => 'Female'];
        $this->actingAs($owner)->postJson('/api/v1/pets', $data)->assertOk();
        $pet = Pet::where('name', 'CRUD companion')->firstOrFail();
        $this->getJson('/api/v1/pets/'.$pet->id)->assertOk()->assertJsonPath('data.name', 'CRUD companion');
        $this->putJson('/api/v1/pets/'.$pet->id, array_replace($data, ['name' => 'Updated companion']))->assertOk();
        $this->assertDatabaseHas('pets', ['id' => $pet->id, 'name' => 'Updated companion']);
        $this->deleteJson('/api/v1/pets/'.$pet->id)->assertOk();
        $this->getJson('/api/v1/pets/'.$pet->id)->assertNotFound();
        $this->assertModelMissing($pet);
    }

    public function test_pet_deletion_enforces_ownership_role_and_clinical_history(): void
    {
        $visit = Appointment::factory()->cancelled()->create();
        $pet = $visit->pet;
        $other = User::factory()->create();
        $this->actingAs($other)->deleteJson('/api/v1/pets/'.$pet->id)->assertNotFound();
        $this->actingAs($visit->veterinarian->user)->deleteJson('/api/v1/pets/'.$pet->id)->assertForbidden();
        $this->actingAs($pet->owner->user)->deleteJson('/api/v1/pets/'.$pet->id)->assertUnprocessable()->assertJsonValidationErrors('delete');
        $this->assertModelExists($pet);
        $this->assertModelExists($visit);
        $this->deleteJson('/api/v1/appointments/'.$visit->id)->assertStatus(405);
        $this->assertModelExists($visit);
    }

    public function test_service_crud_retains_services_used_by_visits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['name' => 'CRUD service', 'price' => '250.00'];
        $this->actingAs($admin)->postJson('/api/v1/services', $data)->assertOk();
        $service = Service::where('name', 'CRUD service')->firstOrFail();
        $this->patchJson('/api/v1/services/'.$service->id, $data + ['active' => false])->assertOk();
        $this->getJson('/api/v1/services/'.$service->id)->assertOk()->assertJsonPath('data.active', false);
        $this->deleteJson('/api/v1/services/'.$service->id)->assertOk();
        $this->assertModelMissing($service);
        $visit = Appointment::factory()->create();
        $this->deleteJson('/api/v1/services/'.$visit->service_id)->assertUnprocessable()->assertJsonValidationErrors('delete');
        $this->assertModelExists($visit->service);
    }

    public function test_unused_medicine_can_be_deleted_but_stock_and_ledger_entries_are_preserved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['name' => 'Unused batch', 'unit' => 'tablet', 'unit_price' => '20.00', 'quantity' => 0, 'reorder_level' => 5, 'expires_at' => today()->addYear()->toDateString()];
        $this->actingAs($admin)->postJson('/api/v1/inventory', $data)->assertOk();
        $medicine = Medicine::where('name', 'Unused batch')->firstOrFail();
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->patchJson('/api/v1/inventory/'.$medicine->id, array_replace($data, ['name' => 'Edited batch', 'active' => true]))->assertOk();
        $this->getJson('/api/v1/inventory/'.$medicine->id)->assertOk()->assertJsonPath('data.name', 'Edited batch');
        $this->deleteJson('/api/v1/inventory/'.$medicine->id)->assertOk();
        $this->assertModelMissing($medicine);
        $stocked = Medicine::factory()->create();
        $this->deleteJson('/api/v1/inventory/'.$stocked->id)->assertUnprocessable();
        $this->patchJson('/api/v1/inventory/'.$stocked->id, ['operation' => 'stock', 'quantity_change' => -$stocked->quantity, 'notes' => 'Remove remaining stock'])->assertOk();
        $this->deleteJson('/api/v1/inventory/'.$stocked->id)->assertUnprocessable()->assertJsonValidationErrors('delete');
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertModelExists($stocked);
    }

    public function test_profile_api_updates_and_deletes_use_profile_ids(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach (['owners' => 'owner', 'veterinarians' => 'veterinarian'] as $section => $role) {
            $data = ['name' => 'CRUD '.$role, 'email' => $role.'@crud.test', 'password' => 'valid-password-123', 'role' => $role];
            $this->postJson('/api/v1/'.$section, $data)->assertOk();
            $account = User::where('email', $data['email'])->firstOrFail();
            $profile = $role === 'owner' ? $account->owner : $account->veterinarian;
            $this->assertNotSame($account->id, $profile->id);
            $this->patchJson('/api/v1/'.$section.'/'.$profile->id, ['name' => 'Edited '.$role, 'email' => $data['email'], 'active' => true, 'phone' => '12345', 'specialization' => 'Small animals', 'role' => 'admin'])->assertOk();
            $this->assertDatabaseHas('users', ['id' => $account->id, 'name' => 'Edited '.$role, 'role' => $role]);
            $this->getJson('/api/v1/'.$section.'/'.$profile->id)->assertOk();
            $this->deleteJson('/api/v1/'.$section.'/'.$profile->id)->assertOk();
            $this->assertModelMissing($account);
            $this->assertModelMissing($profile);
        }
        $this->assertModelExists($admin);
    }

    public function test_deleting_unused_account_removes_profiles_and_authentication_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();
        $profile = $target->owner;
        DB::table('sessions')->insert(['id' => 'unused-account-session', 'user_id' => $target->id, 'payload' => 'unused', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $target->email, 'token' => 'unused']);
        $this->actingAs($admin)->deleteJson('/api/v1/users/'.$target->id)->assertOk();
        $this->assertModelMissing($target);
        $this->assertModelMissing($profile);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->deleteJson('/api/v1/users/'.$admin->id)->assertUnprocessable();
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $this->deleteJson('/api/v1/users/'.$otherAdmin->id)->assertUnprocessable();
    }

    public function test_accounts_with_clinic_or_audit_history_cannot_be_deleted(): void
    {
        $visit = Appointment::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->deleteJson('/api/v1/owners/'.$visit->pet->owner_id)->assertUnprocessable();
        $this->deleteJson('/api/v1/veterinarians/'.$visit->veterinarian_id)->assertUnprocessable();
        $staff = User::factory()->create(['role' => 'veterinarian']);
        $medicine = Medicine::factory()->create();
        InventoryTransaction::create(['medicine_id' => $medicine->id, 'user_id' => $staff->id, 'quantity_change' => 1, 'balance_after' => 51, 'type' => 'adjustment', 'notes' => 'Audit record']);
        $this->deleteJson('/api/v1/users/'.$staff->id)->assertUnprocessable();
        $this->assertModelExists($staff);
        $this->assertModelExists($staff->veterinarian);
    }

    public function test_livewire_deletion_refreshes_the_directory_and_shows_history_errors(): void
    {
        $pet = Pet::factory()->create();
        $owner = $pet->owner->user;
        Livewire::actingAs($owner)->test(Workspace::class, ['section' => 'pets'])
            ->assertSeeHtml('wire:confirm=')->call('deleteRecord', $pet->id)
            ->assertHasNoErrors()->assertDispatched('clinic-saved')->assertDispatched('clinic-toast')->assertDontSee($pet->name);
        $this->assertModelMissing($pet);
        $visit = Appointment::factory()->create(['pet_id' => Pet::factory()->create(['owner_id' => $owner->owner->id])->id]);
        Livewire::test(Workspace::class, ['section' => 'pets'])->call('deleteRecord', $visit->pet_id)->assertHasErrors('delete')->assertSee('has clinic history');
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['services', 'inventory', 'users', 'owners', 'veterinarians'] as $section) {
            $this->actingAs($admin)->get('/clinic/'.$section)->assertOk();
        }
    }

    public function test_web_deletion_and_api_authentication_are_protected(): void
    {
        $pet = Pet::factory()->create();
        $this->deleteJson('/api/v1/pets/'.$pet->id)->assertUnauthorized();
        $owner = $pet->owner->user;
        $this->actingAs($owner)->from('/clinic/pets')->delete('/clinic/pets/'.$pet->id)->assertRedirect('/clinic/pets')->assertSessionHas('success');
        $this->assertModelMissing($pet);
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->deleteJson('/api/v1/pets/1')->assertForbidden();
        $inactive = User::factory()->create(['active' => false]);
        $this->actingAs($inactive)->deleteJson('/api/v1/pets/1')->assertForbidden();
    }
}
