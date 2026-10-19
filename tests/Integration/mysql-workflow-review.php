<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Services\ClinicAccounts;
use App\Services\ClinicCommands;
use App\Services\ClinicDeletion;
use App\Services\ClinicWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'MySQL workflow review failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$source = DB::getDefaultConnection();
$connection = DB::connection($source)->getConfig();
if ($connection['driver'] !== 'mysql') {
    throw new RuntimeException('This review requires MySQL.');
}
$database = 'vetcare_review_'.bin2hex(random_bytes(8));
if (! preg_match('/^vetcare_review_[a-f0-9]{16}$/', $database)) {
    throw new RuntimeException('Invalid isolated database name.');
}
// No statements change the configured clinic database. All fixtures use a new,
// uniquely named database, removed below after both test connections are closed.
DB::connection($source)->statement('CREATE DATABASE `'.$database.'`');
$checks = [];
try {
    foreach (['review_write', 'review_snapshot'] as $name) {
        config(['database.connections.'.$name => array_replace($connection, ['database' => $database, 'url' => null, 'name' => $name, 'read' => null, 'write' => null])]);
        $isolated = DB::connection($name);
        if ($isolated->getName() !== $name || $isolated->selectOne('SELECT DATABASE() AS current_database')->current_database !== $database) {
            throw new RuntimeException('The review connection must resolve to its isolated database before migrations run.');
        }
    }
    DB::setDefaultConnection('review_write');
    Artisan::call('migrate', ['--database' => 'review_write', '--force' => true]);
    $admin = User::factory()->admin()->create();
    $vet = User::factory()->veterinarian()->create();
    $first = Pet::factory()->create();
    $second = Pet::factory()->create();
    $service = Service::factory()->create(['price' => 500]);
    $booking = ['pet_id' => $first->id, 'veterinarian_id' => $vet->veterinarian->id, 'service_id' => $service->id, 'appointment_date' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'), 'reason' => 'Isolated transaction review'];
    $snapshot = DB::connection('review_snapshot');
    $snapshot->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $startSnapshot = function () use ($snapshot) {
        $snapshot->beginTransaction();
        $snapshot->table('owners')->first();
    };
    $attemptFromSnapshot = function (array $data, User $actor) {
        DB::setDefaultConnection('review_snapshot');
        try {
            app(ClinicWorkflow::class)->book($actor, $data);

            return false;
        } catch (ValidationException|HttpExceptionInterface|ModelNotFoundException $e) {
            return true;
        } finally {
            DB::connection('review_snapshot')->rollBack();
            DB::setDefaultConnection('review_write');
        }
    };

    // Represents the snapshot created before a booking waits for another transaction's vet lock.
    $startSnapshot();
    app(ClinicWorkflow::class)->book($first->owner->user, $booking);
    $checks['repeatable_read_double_booking_rejected'] = $attemptFromSnapshot(array_replace($booking, ['pet_id' => $second->id]), $second->owner->user);

    $otherVet = User::factory()->veterinarian()->create();
    $inactiveBooking = array_replace($booking, ['pet_id' => $second->id, 'veterinarian_id' => $otherVet->veterinarian->id]);
    $startSnapshot();
    app(ClinicAccounts::class)->update($admin, $otherVet, ['name' => $otherVet->name, 'email' => $otherVet->email, 'active' => false]);
    $checks['repeatable_read_inactive_vet_rejected'] = $attemptFromSnapshot($inactiveBooking, $second->owner->user);

    $otherService = Service::factory()->create();
    $startSnapshot();
    $otherService->update(['active' => false]);
    $checks['repeatable_read_inactive_service_rejected'] = $attemptFromSnapshot(array_replace($booking, ['pet_id' => $second->id, 'service_id' => $otherService->id, 'appointment_date' => now()->addDays(2)->format('Y-m-d H:i:s')]), $second->owner->user);
    $checks['only_one_committed_booking'] = Appointment::count() === 1;

    // Exercise the clinical/billing/stock workflow on the same isolated MySQL schema.
    $commands = app(ClinicCommands::class);
    $medicine = $commands->store($admin, 'inventory', ['name' => 'Review stock', 'quantity' => 10, 'reorder_level' => 2, 'unit' => 'tablet', 'unit_price' => 15, 'expires_at' => today()->addYear()->toDateString()]);
    $external = $commands->store($admin, 'inventory', ['name' => 'External prescription', 'quantity' => 0, 'reorder_level' => 2, 'unit' => 'tablet', 'unit_price' => 25, 'expires_at' => today()->subDay()->toDateString()]);
    $visit = Appointment::firstOrFail();
    $commands->update($vet, 'appointments', $visit->id, ['status' => 'confirmed']);
    $record = $commands->store($vet, 'records', [
        'appointment_id' => $visit->id, 'symptoms' => 'Review findings', 'diagnosis' => 'Review assessment', 'treatment' => 'Review plan',
        'items' => [
            ['medicine_id' => $medicine->getKey(), 'quantity' => 2, 'dosage' => 'Clinician directions', 'dispense_now' => true],
            ['medicine_id' => $external->getKey(), 'quantity' => 5, 'dosage' => 'External prescription directions', 'dispense_now' => false],
        ],
        'vaccinations' => [
            ['vaccine_name' => 'Review Vaccine A', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addYear()->toDateString()],
            ['vaccine_name' => 'Review Vaccine B', 'date_given' => today()->toDateString(), 'next_due_date' => today()->addMonths(6)->toDateString()],
        ],
    ]);
    $invoice = Invoice::where('appointment_id', $visit->id)->firstOrFail();
    $checks['mysql_clinical_completion_and_vaccines'] = $visit->fresh()->status === 'completed' && $record->vaccinations()->count() === 2;
    $checks['mysql_dispensing_and_prescription_only_stock'] = Medicine::findOrFail($medicine->getKey())->quantity === 8 && Medicine::findOrFail($external->getKey())->quantity === 0;
    $checks['mysql_invoice_snapshot_total'] = $invoice->balanceCents() === 53000;
    $payment = ['invoice_id' => $invoice->id, 'amount' => '100.00', 'method' => 'cash', 'paid_at' => now()->utc()->toISOString(), 'request_key' => (string) Str::uuid()];
    $commands->store($admin, 'payments', $payment);
    $commands->store($admin, 'payments', $payment);
    $checks['mysql_payment_retry'] = $invoice->fresh()->payments()->count() === 1 && $invoice->fresh()->balanceCents() === 43000;
    $commands->store($admin, 'payments', array_replace($payment, ['amount' => '430.00', 'method' => 'bank_transfer', 'reference' => 'REVIEW-ONLY', 'request_key' => (string) Str::uuid()]));
    $checks['mysql_final_payment'] = $invoice->fresh()->balanceCents() === 0;
    $blocked = false;
    try {
        app(ClinicDeletion::class)->delete($admin, 'services', $service->id);
    } catch (ValidationException $e) {
        $blocked = true;
    }
    $checks['mysql_history_deletion_guard'] = $blocked && Service::whereKey($service->id)->exists();
    $unused = $commands->store($admin, 'inventory', ['name' => 'Unused batch', 'quantity' => 0, 'reorder_level' => 2, 'unit' => 'tablet', 'unit_price' => 15, 'expires_at' => today()->addYear()->toDateString()]);
    app(ClinicDeletion::class)->delete($admin, 'inventory', $unused->getKey());
    $checks['mysql_unused_record_deletion'] = ! Medicine::whereKey($unused->getKey())->exists();
    echo json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    foreach (['review_write', 'review_snapshot'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
        DB::purge($name);
    }
    DB::setDefaultConnection($source);
    DB::connection($source)->statement('DROP DATABASE `'.$database.'`');
}
exit(in_array(false, $checks, true) ? 1 : 0);
