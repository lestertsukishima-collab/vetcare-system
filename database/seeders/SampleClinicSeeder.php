<?php

namespace Database\Seeders;

use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Services\ClinicWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SampleClinicSeeder extends Seeder
{
    public const PASSWORD = 'VetCareDemo!2026';

    public const EMAILS = ['admin@vetcare.test', 'vet@vetcare.test', 'vet2@vetcare.test', 'owner@vetcare.test', 'owner2@vetcare.test', 'owner3@vetcare.test'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('SampleClinicSeeder is available only in local and testing environments.');
        }

        DB::transaction(function (): bool {
            $existing = User::whereIn('email', self::EMAILS)->count();
            if ($existing === count(self::EMAILS)) {
                return false;
            }
            if ($existing > 0) {
                throw new RuntimeException('Some reserved sample emails already exist. No sample data was added and existing accounts were left unchanged.');
            }

            $admin = User::factory()->admin()->create(['name' => 'Demo Administrator', 'email' => self::EMAILS[0], 'password' => self::PASSWORD]);
            $vets = [
                User::factory()->veterinarian()->create(['name' => 'Dr. Sofia Reyes (Demo)', 'email' => self::EMAILS[1], 'password' => self::PASSWORD]),
                User::factory()->veterinarian()->create(['name' => 'Dr. Daniel Cruz (Demo)', 'email' => self::EMAILS[2], 'password' => self::PASSWORD]),
            ];
            foreach ($vets as $index => $vet) {
                $vet->veterinarian()->update(['license_number' => 'DEMO-VET-00'.($index + 1), 'specialization' => $index === 0 ? 'Small animal care' : 'Preventive care']);
            }
            $owners = [];
            foreach (['Alex Santos (Demo)', 'Jamie Garcia (Demo)', 'Morgan Rivera (Demo)'] as $index => $name) {
                $owner = User::factory()->owner()->create(['name' => $name, 'email' => self::EMAILS[$index + 3], 'password' => self::PASSWORD]);
                $owner->owner()->update(['phone' => '0900000000'.($index + 1), 'address' => ($index + 1).' Example Street, Sample City']);
                $owners[] = $owner;
            }
            if (! DB::table('clinic_settings')->exists()) {
                DB::table('clinic_settings')->insert(['id' => 1, 'name' => 'VetCare Sample Clinic', 'phone' => '09000000000', 'address' => 'Example Avenue, Sample City', 'created_at' => now(), 'updated_at' => now()]);
            }

            $services = [];
            foreach ([['Wellness consultation', 500], ['Vaccination visit', 650], ['Follow-up consultation', 350], ['Dental assessment', 800], ['Archived service', 400]] as $index => [$name, $price]) {
                $services[] = Service::factory()->create(['name' => $name.' (sample)', 'price' => $price, 'active' => $index !== 4]);
            }
            $medicines = [];
            foreach ([
                ['Wellness supplement', 'tablet', 25, 80, 10, 12, true],
                ['Topical care', 'bottle', 120, 40, 8, 9, true],
                ['Oral care', 'unit', 50, 50, 10, 6, true],
                ['Low stock supplement', 'tablet', 20, 3, 10, 8, true],
                ['Expired demonstration batch', 'vial', 75, 4, 5, -1, true],
                ['Archived medicine', 'unit', 15, 0, 5, 6, false],
            ] as [$name, $unit, $price, $quantity, $reorder, $months, $active]) {
                $medicine = Medicine::factory()->create(['name' => $name.' (sample)', 'unit' => $unit, 'unit_price' => $price, 'quantity' => $quantity, 'reorder_level' => $reorder, 'expires_at' => today()->addMonths($months), 'active' => $active]);
                $medicine->transactions()->update(['user_id' => $admin->id, 'notes' => 'Sample opening inventory.', 'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90)]);
                $medicines[] = $medicine;
            }

            $pets = [];
            foreach ([
                ['Milo', 'Cat', 'Domestic shorthair', 'Male', 3, 4.25, 'Ginger'],
                ['Luna', 'Dog', 'Golden retriever', 'Female', 4, 25.50, 'Golden'],
                ['Coco', 'Dog', 'Shih Tzu', 'Female', 2, 5.20, 'White and brown'],
                ['Oreo', 'Cat', 'Domestic shorthair', 'Male', 5, 4.80, 'Black and white'],
                ['Max', 'Dog', 'Aspin', 'Male', 6, 18.75, 'Brown'],
                ['Bella', 'Cat', 'Persian', 'Female', 3, 3.90, 'White'],
                ['Nala', 'Dog', 'Beagle', 'Female', 2, 10.50, 'Tricolor'],
                ['Charlie', 'Rabbit', 'Lop', 'Male', 1, 1.80, 'Gray'],
                ['Kiwi', 'Bird', 'Budgerigar', 'Unknown', 1, 0.04, 'Green and yellow'],
                ['Pepper', 'Cat', 'Mixed breed', 'Male', 4, 4.10, 'Black'],
            ] as $index => [$name, $species, $breed, $sex, $years, $weight, $color]) {
                $pets[] = Pet::factory()->for($owners[$index % 3]->owner)->create(['name' => $name, 'species' => $species, 'breed' => $breed, 'sex' => $sex, 'birth_date' => today()->subYears($years)->subMonths(2), 'weight' => $weight, 'color' => $color, 'notes' => $index === 1 ? 'Sample note: prefers a quiet waiting area.' : 'Demonstration patient; no clinical instructions.']);
            }

            $workflow = app(ClinicWorkflow::class);
            // Opening + receipt + adjustment + dispensing all reconcile to the current balance.
            $workflow->adjustStock($admin, $medicines[0]->id, ['quantity_change' => 20, 'notes' => 'Sample delivery DEMO-DEL-001.']);
            $workflow->adjustStock($admin, $medicines[0]->id, ['quantity_change' => -2, 'notes' => 'Sample stock-count adjustment.']);
            $medicines[0]->transactions()->where('type', '!=', 'opening')->update(['created_at' => now()->subDays(75), 'updated_at' => now()->subDays(75)]);

            $dayOffsets = [60, 45, 30, 14, 9, 6, 5, 4, 3, 2, 1, 0];
            foreach ($dayOffsets as $index => $daysAgo) {
                $pet = $pets[$index % count($pets)];
                $vet = $vets[$index % 2];
                $at = $daysAgo === 0 ? now()->subHours(2) : today()->subDays($daysAgo)->setTime(10, 0);
                $visit = $workflow->book($admin, ['pet_id' => $pet->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $services[$index % 4]->id, 'appointment_date' => $at->format('Y-m-d H:i:s'), 'reason' => 'Sample wellness visit and preventive care review.']);
                $workflow->status($vet, $visit->id, 'confirmed');
                $items = [['medicine_id' => $medicines[$index % 3]->id, 'quantity' => 2, 'dosage' => 'Demonstration directions only; not for clinical use.', 'dispense_now' => $index % 4 !== 0]];
                if ($index % 3 === 0) {
                    $items[] = ['medicine_id' => $medicines[1]->id, 'quantity' => 1, 'dosage' => 'Sample directions only.', 'dispense_now' => true];
                }
                $vaccinations = [];
                if ($index % 2 === 0) {
                    $dueOffset = match ($index) {
                        0 => -5, 2 => 10, 4 => -1, 8 => 20, default => 365
                    };
                    $vaccinations[] = ['vaccine_name' => 'Sample preventive vaccine', 'date_given' => $at->toDateString(), 'next_due_date' => today()->addDays($dueOffset)->toDateString(), 'batch_number' => 'DEMO-LOT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)];
                }
                $record = $workflow->examine($vet, ['appointment_id' => $visit->id, 'symptoms' => 'Sample findings from a routine wellness visit.', 'diagnosis' => 'Sample wellness assessment.', 'treatment' => 'Sample follow-up plan for demonstration.', 'notes' => 'Fictional clinical data; not treatment instructions.', 'instructions' => 'Demonstration prescription; not for clinical use.', 'items' => $items, 'vaccinations' => $vaccinations]);
                $record->update(['created_at' => $at, 'updated_at' => $at]);
                $record->prescription?->update(['created_at' => $at, 'updated_at' => $at]);
                $record->prescription?->items()->update(['created_at' => $at, 'updated_at' => $at]);
                $record->vaccinations()->update(['created_at' => $at, 'updated_at' => $at]);
                InventoryTransaction::whereIn('prescription_item_id', $record->prescription?->items()->select('id') ?? [])->update(['created_at' => $at, 'updated_at' => $at]);
                $invoice = Invoice::where('appointment_id', $visit->id)->firstOrFail();
                $invoice->update(['due_date' => $at->copy()->addDays(7)->toDateString(), 'created_at' => $at, 'updated_at' => $at]);
                if ($index % 3 !== 2) {
                    $method = $index % 2 === 0 ? 'cash' : 'bank_transfer';
                    $payment = $workflow->payment($admin, ['invoice_id' => $invoice->id, 'amount' => ($index % 3 === 0 ? Invoice::cents($invoice->total) : intdiv(Invoice::cents($invoice->total), 2)) / 100, 'method' => $method, 'reference' => $method === 'cash' ? null : 'DEMO-RECEIPT-'.($index + 1), 'paid_at' => $at->copy()->addMinutes(45)->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()]);
                    $payment->update(['created_at' => $payment->paid_at, 'updated_at' => $payment->paid_at]);
                }
            }

            foreach (range(0, 11) as $index) {
                $at = $index < 2 ? now()->addHours($index + 2)->startOfHour() : today()->addDays(1 + intdiv($index - 2, 4))->setTime(9 + (($index - 2) % 4), 0);
                $visit = $workflow->book($admin, ['pet_id' => $pets[$index % count($pets)]->id, 'veterinarian_id' => $vets[$index % 2]->veterinarian->id, 'service_id' => $services[$index % 4]->id, 'appointment_date' => $at->format('Y-m-d H:i:s'), 'reason' => $index >= 10 ? 'Sample cancelled visit.' : 'Sample upcoming consultation.']);
                if ($index >= 10) {
                    $workflow->status($admin, $visit->id, 'cancelled');
                } elseif ($index % 2 === 0) {
                    $workflow->status($admin, $visit->id, 'confirmed');
                }
            }

            return true;
        });

    }
}
