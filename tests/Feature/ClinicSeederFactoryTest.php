<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Service;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Veterinarian;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ClinicSeederFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_and_profile_factories_do_not_duplicate_automatic_profiles(): void
    {
        $owner = User::factory()->owner()->create();
        $vet = User::factory()->veterinarian()->create();
        $admin = User::factory()->admin()->create();
        $this->assertSame('owner', $owner->role);
        $this->assertSame(1, $owner->owner()->count());
        $this->assertSame('veterinarian', $vet->role);
        $this->assertSame(1, $vet->veterinarian()->count());
        $this->assertNull($admin->owner);
        $this->assertNull($admin->veterinarian);
        $profile = Owner::factory()->create();
        $vetProfile = Veterinarian::factory()->create();
        $this->assertSame('owner', $profile->user->role);
        $this->assertSame('veterinarian', $vetProfile->user->role);
        $this->assertSame(2, Owner::count());
        $this->assertSame(2, Veterinarian::count());
        $this->assertFalse(User::factory()->inactive()->create()->active);
    }

    public function test_factories_create_valid_related_records_and_catalog_snapshots(): void
    {
        $pet = Pet::factory()->cat()->create();
        $visit = Appointment::factory()->for($pet)->confirmed()->create();
        $this->assertSame($visit->service->price, $visit->service_fee);
        $this->assertSame($visit->service->name, $visit->service_name);
        $this->assertSame($pet->id, $visit->pet_id);
        $this->assertSame('confirmed', $visit->status);
        $record = MedicalRecord::factory()->create();
        $this->assertSame('completed', $record->appointment->status);
        $this->assertSame($record->appointment->pet_id, $record->pet_id);
        $this->assertSame($record->appointment->veterinarian_id, $record->veterinarian_id);
        $vaccine = Vaccination::factory()->for($record, 'medicalRecord')->create();
        $this->assertSame($record->pet_id, $vaccine->pet_id);
        $this->assertSame($record->veterinarian_id, $vaccine->veterinarian_id);
        $this->assertTrue($vaccine->date_given->isSameDay($record->appointment->appointment_date));
        $this->assertTrue($vaccine->next_due_date->gt($vaccine->date_given));
        $prescription = Prescription::factory()->for($record, 'medicalRecord')->create();
        $item = PrescriptionItem::factory()->for($prescription)->create();
        $this->assertSame(0, $item->dispensed_quantity);
        $this->assertSame($item->medicine->name, $item->medicine_name);
        $this->assertSame($item->medicine->unit, $item->medicine_unit);
        $this->assertSame($item->medicine->unit_price, $item->unit_price);
        $this->assertSame(50, $item->medicine->quantity);
        $invoice = Invoice::factory()->for($record->appointment)->create();
        $this->assertSame(Invoice::cents($invoice->service_amount), Invoice::cents($invoice->total));
        $first = Payment::factory()->for($invoice)->create();
        $this->assertGreaterThan(0, $invoice->fresh()->balanceCents());
        $this->assertSame('admin', $first->recorder->role);
        Payment::factory()->for($invoice)->fullBalance()->create();
        $this->assertSame(0, $invoice->fresh()->balanceCents());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_inventory_factory_balances_and_useful_states(): void
    {
        $medicine = Medicine::factory()->lowStock()->create();
        $this->assertLessThanOrEqual($medicine->reorder_level, $medicine->quantity);
        $this->assertSame($medicine->quantity, $medicine->transactions()->sum('quantity_change'));
        $receipt = InventoryTransaction::factory()->for($medicine)->create();
        $this->assertSame(13, $receipt->balance_after);
        $this->assertSame(13, $medicine->fresh()->quantity);
        $this->assertSame(13, $medicine->transactions()->sum('quantity_change'));
        InventoryTransaction::factory()->count(2)->for($medicine)->create();
        $this->assertSame(33, $medicine->fresh()->quantity);
        $this->assertSame([3, 13, 23, 33], $medicine->transactions()->orderBy('id')->pluck('balance_after')->all());
        $this->assertTrue(Medicine::factory()->expired()->create()->expires_at->lt(today()));
        $this->assertFalse(Medicine::factory()->inactive()->create()->active);
        $this->assertFalse(Service::factory()->inactive()->create()->active);
        $this->assertSame('cancelled', Appointment::factory()->cancelled()->create()->status);
    }

    public function test_standalone_factories_build_their_default_relationships(): void
    {
        foreach ([User::class, Owner::class, Veterinarian::class, Pet::class, Appointment::class, MedicalRecord::class, Vaccination::class, Prescription::class, PrescriptionItem::class, Medicine::class, Service::class, Invoice::class, Payment::class, InventoryTransaction::class] as $model) {
            $record = $model::factory()->create();
            $this->assertTrue($record->exists, $model.' did not create its default fixture.');
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_default_seeder_populates_every_clinic_table_with_consistent_workflows(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (['users' => 6, 'owners' => 3, 'veterinarians' => 2, 'pets' => 10, 'appointments' => 24, 'medical_records' => 12, 'vaccinations' => 6, 'prescriptions' => 12, 'medicines' => 6, 'prescription_items' => 16, 'services' => 5, 'invoices' => 12, 'payments' => 8, 'inventory_transactions' => 21] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        foreach (User::all() as $user) {
            $this->assertTrue($user->hasVerifiedEmail());
            $this->assertTrue(Hash::check(SampleClinicSeeder::PASSWORD, $user->password));
        }
        $this->assertSame(12, Appointment::where('status', 'completed')->count());
        $this->assertSame(5, Appointment::where('status', 'pending')->count());
        $this->assertSame(5, Appointment::where('status', 'confirmed')->count());
        $this->assertSame(2, Appointment::where('status', 'cancelled')->count());
        foreach (Medicine::all() as $medicine) {
            $balance = 0;
            foreach ($medicine->transactions()->orderBy('id')->get() as $movement) {
                $balance += $movement->quantity_change;
                $this->assertSame($balance, $movement->balance_after);
            }
            $this->assertSame($balance, $medicine->quantity);
        }
        foreach (Invoice::with(['appointment.medicalRecord.prescription.items', 'payments'])->get() as $invoice) {
            $medicineCents = $invoice->appointment->medicalRecord->prescription->items->sum(fn ($item) => Invoice::cents($item->unit_price) * $item->dispensed_quantity);
            $this->assertSame($medicineCents, Invoice::cents($invoice->medicine_amount));
            $this->assertSame(Invoice::cents($invoice->service_amount) + $medicineCents, Invoice::cents($invoice->total));
        }
        $this->assertSame(4, Invoice::all()->filter(fn ($invoice) => $invoice->balanceCents() === 0)->count());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_repeated_seeding_preserves_accounts_settings_and_existing_data(): void
    {
        $existing = User::factory()->owner()->create(['email' => 'existing@example.test']);
        $existingPet = Pet::factory()->for($existing->owner)->create(['name' => 'Existing patient']);
        DB::table('clinic_settings')->insert(['id' => 1, 'name' => 'Existing Clinic']);
        $this->seed(SampleClinicSeeder::class);
        $admin = User::where('email', SampleClinicSeeder::EMAILS[0])->firstOrFail();
        $admin->update(['name' => 'Updated sample admin', 'password' => 'Changed-demo-password-2026!']);
        $passwordHash = $admin->fresh()->password;
        $counts = [];
        foreach (['users', 'owners', 'veterinarians', 'pets', 'appointments', 'medical_records', 'payments', 'inventory_transactions'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->seed(DatabaseSeeder::class);
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame($passwordHash, $admin->fresh()->password);
        $this->assertSame('Updated sample admin', $admin->fresh()->name);
        $this->assertSame('Existing patient', $existingPet->fresh()->name);
        $this->assertDatabaseHas('clinic_settings', ['name' => 'Existing Clinic']);
    }

    public function test_reserved_email_collision_does_not_overwrite_an_existing_account(): void
    {
        $existing = User::factory()->owner()->create(['email' => SampleClinicSeeder::EMAILS[0]]);
        try {
            app(SampleClinicSeeder::class)->run();
            $this->fail('A partial sample-account collision should abort seeding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reserved sample emails', $exception->getMessage());
        }
        $this->assertSame('owner', $existing->fresh()->role);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_sample_seeding_is_unavailable_in_production(): void
    {
        $this->app->instance('env', 'production');
        try {
            app(SampleClinicSeeder::class)->run();
            $this->fail('Sample accounts must not be created in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local and testing', $exception->getMessage());
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_sample_accounts_can_render_their_populated_workspaces(): void
    {
        $this->seed(SampleClinicSeeder::class);
        foreach (['admin@vetcare.test', 'vet@vetcare.test', 'owner@vetcare.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)->get('/dashboard')->assertOk();
            foreach (['pets', 'appointments', 'records', 'prescriptions', 'vaccinations'] as $page) {
                $this->get('/clinic/'.$page)->assertOk();
            }
        }
        $admin = User::where('email', 'admin@vetcare.test')->firstOrFail();
        foreach (['inventory', 'transactions', 'invoices', 'payments', 'reports'] as $page) {
            $this->actingAs($admin)->get('/clinic/'.$page)->assertOk();
        }
    }
}
