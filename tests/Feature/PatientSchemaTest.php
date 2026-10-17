<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PatientSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function visit(User $owner, User $vet): Appointment
    {
        $pet = $owner->owner->pets()->create(['name' => 'Schema Pet', 'species' => 'Dog', 'sex' => 'Male']);
        $service = Service::create(['name' => 'Schema consultation', 'price' => 500]);

        return Appointment::create(['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'service_fee' => 500, 'appointment_date' => now()->addDay(), 'reason' => 'Schema visit', 'status' => 'confirmed']);
    }

    public function test_requested_columns_exist_and_renamed_columns_are_replaced(): void
    {
        foreach ([
            'owners' => ['id', 'user_id', 'phone', 'address'],
            'pets' => ['id', 'owner_id', 'name', 'species', 'breed', 'sex', 'birth_date', 'weight', 'color'],
            'appointments' => ['id', 'pet_id', 'veterinarian_id', 'appointment_date', 'reason', 'status'],
            'medical_records' => ['id', 'appointment_id', 'pet_id', 'veterinarian_id', 'symptoms', 'diagnosis', 'treatment', 'notes'],
            'vaccinations' => ['id', 'pet_id', 'veterinarian_id', 'vaccine_name', 'date_given', 'next_due_date'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), $table.' is missing a requested column.');
        }
        $this->assertFalse(Schema::hasColumn('appointments', 'scheduled_at'));
        $this->assertFalse(Schema::hasColumn('medical_records', 'examination'));
        $this->assertFalse(Schema::hasColumn('vaccinations', 'administered_at'));
        $this->assertFalse(Schema::hasColumn('vaccinations', 'next_due_at'));
        $this->assertFalse(Schema::hasColumn('vaccinations', 'vaccine'));
    }

    public function test_pet_weight_and_color_can_be_created_updated_and_validated(): void
    {
        $owner = $this->account();
        $data = ['name' => 'Milo', 'species' => 'Cat', 'breed' => 'Mixed', 'sex' => 'Male', 'birth_date' => today()->subYear()->toDateString(), 'weight' => '4.25', 'color' => 'Ginger'];
        $this->actingAs($owner)->post('/clinic/pets', $data)->assertSessionHasNoErrors();
        $pet = Pet::firstOrFail();
        $this->assertSame('4.25', $pet->weight);
        $this->assertSame('Ginger', $pet->color);
        $data['weight'] = '-1';
        $this->patch('/clinic/pets/'.$pet->id, $data)->assertSessionHasErrors('weight');
        $data['weight'] = '4.321';
        $this->patch('/clinic/pets/'.$pet->id, $data)->assertSessionHasErrors('weight');
        $data['weight'] = '4.50';
        $data['color'] = 'Ginger and white';
        $this->patch('/clinic/pets/'.$pet->id, $data)->assertSessionHasNoErrors();
        $this->get('/clinic/pets')->assertSee('4.50 kg')->assertSee('Ginger and white')->assertSee('name="weight"', false)->assertSee('name="color"', false);
    }

    public function test_clinical_forms_save_canonical_fields_and_authoritative_patient_links(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $otherVet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        $otherPet = $this->account()->owner->pets()->create(['name' => 'Other Pet', 'species' => 'Cat', 'sex' => 'Female']);
        $this->actingAs($vet)->get('/clinic/records')->assertOk()->assertSee('name="symptoms"', false)->assertSee('name="notes"', false)->assertSee('vaccinations[0][vaccine_name]', false)->assertSee('vaccinations[0][date_given]', false)->assertSee('vaccinations[0][next_due_date]', false);
        $this->post('/clinic/records', [
            'appointment_id' => $visit->id, 'pet_id' => $otherPet->id, 'veterinarian_id' => $otherVet->veterinarian->id,
            'symptoms' => 'Recorded symptoms', 'diagnosis' => 'Recorded diagnosis', 'treatment' => 'Recorded treatment', 'notes' => 'Additional clinical notes',
            'vaccinations' => [['vaccine_name' => 'Schema vaccine', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addYear()->toDateString()]],
        ])->assertSessionHasNoErrors();
        $record = MedicalRecord::firstOrFail();
        $vaccine = Vaccination::firstOrFail();
        $this->assertDatabaseHas('medical_records', ['appointment_id' => $visit->id, 'pet_id' => $visit->pet_id, 'veterinarian_id' => $visit->veterinarian_id, 'symptoms' => 'Recorded symptoms', 'notes' => 'Additional clinical notes']);
        $this->assertDatabaseHas('vaccinations', ['medical_record_id' => $record->id, 'pet_id' => $visit->pet_id, 'veterinarian_id' => $visit->veterinarian_id, 'vaccine_name' => 'Schema vaccine']);
        $this->assertSame($visit->pet_id, $record->pet->id);
        $this->assertSame($vet->id, $vaccine->veterinarian->user_id);
        $this->assertTrue($visit->pet->medicalRecords->contains('id', $record->id));
        $this->assertTrue($vet->veterinarian->vaccinations->contains('id', $vaccine->id));
        $this->actingAs($owner)->get('/clinic/records')->assertSee('Recorded symptoms')->assertSee('Additional clinical notes');
        $this->get('/clinic/vaccinations')->assertSee('Schema vaccine');
        $this->get('/clinic/appointments')->assertSee($visit->appointment_date->format('M d, Y'));
        $this->get('/clinic/appointments')->assertSee('name="appointment_date"', false);
    }

    public function test_migration_preserves_existing_visits_and_backfills_direct_foreign_keys(): void
    {
        $owner = $this->account();
        $vet = $this->account('veterinarian');
        $visit = $this->visit($owner, $vet);
        $date = $visit->appointment_date->format('Y-m-d H:i:s');
        $migration = require database_path('migrations/2026_10_17_000005_align_patient_and_clinical_fields.php');
        $migration->down();
        $recordId = DB::table('medical_records')->insertGetId(['appointment_id' => $visit->id, 'examination' => 'Preserved findings', 'diagnosis' => 'Preserved diagnosis', 'treatment' => 'Preserved treatment']);
        $vaccineId = DB::table('vaccinations')->insertGetId(['medical_record_id' => $recordId, 'vaccine' => 'Preserved vaccine', 'administered_at' => today()->toDateString(), 'next_due_at' => today()->addYear()->toDateString()]);
        $migration->up();
        $this->assertDatabaseHas('appointments', ['id' => $visit->id, 'appointment_date' => $date]);
        $this->assertDatabaseHas('medical_records', ['id' => $recordId, 'pet_id' => $visit->pet_id, 'veterinarian_id' => $visit->veterinarian_id, 'symptoms' => 'Preserved findings']);
        $this->assertDatabaseHas('vaccinations', ['id' => $vaccineId, 'pet_id' => $visit->pet_id, 'veterinarian_id' => $visit->veterinarian_id, 'vaccine_name' => 'Preserved vaccine']);
        $this->assertDatabaseHas('pets', ['id' => $visit->pet_id, 'weight' => null, 'color' => null]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
