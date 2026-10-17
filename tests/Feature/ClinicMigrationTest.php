<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClinicMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_legacy_profiles_history_and_stock(): void
    {
        $files = [
            '2026_10_17_000002_normalize_clinic_schema.php',
            '2026_10_17_000003_preserve_visit_and_prescription_labels.php',
            '2026_10_17_000004_track_dispensing_and_payment_retries.php',
            '2026_10_17_000005_align_patient_and_clinical_fields.php',
        ];
        $migrations = array_map(fn ($file) => require database_path('migrations/'.$file), $files);
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        DB::table('users')->insert([
            ['id' => 42, 'name' => 'Legacy Owner', 'email' => 'legacy-owner@example.test', 'password' => 'unused', 'role' => 'owner'],
            ['id' => 99, 'name' => 'Legacy Vet', 'email' => 'legacy-vet@example.test', 'password' => 'unused', 'role' => 'veterinarian'],
        ]);
        $pet = DB::table('pets')->insertGetId(['owner_id' => 42, 'name' => 'Legacy Pet', 'species' => 'Cat', 'sex' => 'Female']);
        $service = DB::table('services')->insertGetId(['name' => 'Legacy service', 'price' => 450]);
        $appointment = DB::table('appointments')->insertGetId(['pet_id' => $pet, 'veterinarian_id' => 99, 'service_id' => $service, 'scheduled_at' => now(), 'reason' => 'Legacy visit', 'status' => 'completed']);
        $record = DB::table('medical_records')->insertGetId(['appointment_id' => $appointment, 'examination' => 'Legacy findings', 'diagnosis' => 'Legacy diagnosis', 'treatment' => 'Legacy plan', 'prescription' => 'Legacy prescription instructions', 'vaccine' => 'Legacy vaccination', 'next_vaccination_at' => today()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Legacy medicine', 'quantity' => 18, 'reorder_level' => 5, 'expires_at' => today()->addYear()]);
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertDatabaseHas('owners', ['id' => 42, 'user_id' => 42]);
        $this->assertDatabaseHas('veterinarians', ['id' => 99, 'user_id' => 99]);
        $this->assertDatabaseHas('pets', ['id' => $pet, 'owner_id' => 42]);
        $this->assertDatabaseHas('appointments', ['id' => $appointment, 'veterinarian_id' => 99, 'service_fee' => 450]);
        $this->assertDatabaseHas('prescriptions', ['medical_record_id' => $record, 'instructions' => 'Legacy prescription instructions']);
        $this->assertDatabaseHas('medical_records', ['id' => $record, 'pet_id' => $pet, 'veterinarian_id' => 99, 'symptoms' => 'Legacy findings']);
        $this->assertDatabaseHas('vaccinations', ['medical_record_id' => $record, 'pet_id' => $pet, 'veterinarian_id' => 99, 'vaccine_name' => 'Legacy vaccination']);
        $this->assertDatabaseHas('inventory_transactions', ['medicine_id' => $medicine, 'type' => 'opening', 'balance_after' => 18]);
    }
}
