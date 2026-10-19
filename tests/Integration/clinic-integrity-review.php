<?php

use App\Models\Invoice;
use App\Models\Medicine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Clinic integrity review failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$checks = [];
$references = [
    ['owners', 'user_id', 'users'], ['veterinarians', 'user_id', 'users'], ['pets', 'owner_id', 'owners'],
    ['appointments', 'pet_id', 'pets'], ['appointments', 'veterinarian_id', 'veterinarians'], ['appointments', 'service_id', 'services'],
    ['medical_records', 'appointment_id', 'appointments'], ['medical_records', 'pet_id', 'pets'], ['medical_records', 'veterinarian_id', 'veterinarians'],
    ['prescriptions', 'medical_record_id', 'medical_records'], ['prescription_items', 'prescription_id', 'prescriptions'], ['prescription_items', 'medicine_id', 'medicines'],
    ['vaccinations', 'medical_record_id', 'medical_records'], ['vaccinations', 'pet_id', 'pets'], ['vaccinations', 'veterinarian_id', 'veterinarians'],
    ['invoices', 'appointment_id', 'appointments'], ['payments', 'invoice_id', 'invoices'], ['payments', 'recorded_by', 'users'],
    ['inventory_transactions', 'medicine_id', 'medicines'], ['inventory_transactions', 'user_id', 'users'], ['inventory_transactions', 'prescription_item_id', 'prescription_items'],
];
$checks['orphaned_references'] = 0;
foreach ($references as [$table, $column, $parent]) {
    $checks['orphaned_references'] += DB::table($table.' as child')->leftJoin($parent.' as parent', 'parent.id', '=', 'child.'.$column)->whereNotNull('child.'.$column)->whereNull('parent.id')->count();
}
$checks['clinical_reference_mismatches'] = DB::table('medical_records as r')->join('appointments as a', 'a.id', '=', 'r.appointment_id')->where(fn ($q) => $q->whereColumn('r.pet_id', '!=', 'a.pet_id')->orWhereColumn('r.veterinarian_id', '!=', 'a.veterinarian_id'))->count();
$checks['vaccination_reference_mismatches'] = DB::table('vaccinations as v')->join('medical_records as r', 'r.id', '=', 'v.medical_record_id')->where(fn ($q) => $q->whereColumn('r.pet_id', '!=', 'v.pet_id')->orWhereColumn('r.veterinarian_id', '!=', 'v.veterinarian_id'))->count();
$checks['records_on_incomplete_visits'] = DB::table('medical_records as r')->join('appointments as a', 'a.id', '=', 'r.appointment_id')->where('a.status', '!=', 'completed')->count();
$checks['completed_visits_missing_record'] = DB::table('appointments as a')->leftJoin('medical_records as r', 'a.id', '=', 'r.appointment_id')->where('a.status', 'completed')->whereNull('r.id')->count();
$checks['completed_visits_missing_invoice'] = DB::table('appointments as a')->leftJoin('invoices as i', 'a.id', '=', 'i.appointment_id')->where('a.status', 'completed')->whereNull('i.id')->count();
$checks['invoice_total_mismatches'] = 0;
$checks['overpaid_invoices'] = 0;
foreach (Invoice::with(['appointment.medicalRecord.prescription.items', 'payments'])->cursor() as $invoice) {
    $medicineCents = $invoice->appointment->medicalRecord?->prescription?->items->sum(fn ($line) => Invoice::cents($line->unit_price) * $line->dispensed_quantity) ?? 0;
    if (Invoice::cents($invoice->total) !== Invoice::cents($invoice->appointment->service_fee) + $medicineCents || Invoice::cents($invoice->medicine_amount) !== $medicineCents || Invoice::cents($invoice->service_amount) !== Invoice::cents($invoice->appointment->service_fee)) {
        $checks['invoice_total_mismatches']++;
    }
    if ($invoice->payments->sum(fn ($p) => Invoice::cents($p->amount)) > Invoice::cents($invoice->total)) {
        $checks['overpaid_invoices']++;
    }
}
$checks['stock_ledger_mismatches'] = 0;
$checks['stock_running_balance_mismatches'] = 0;
foreach (Medicine::with('transactions')->get() as $medicine) {
    $balance = 0;
    foreach ($medicine->transactions->sortBy('id') as $entry) {
        $balance += $entry->quantity_change;
        if ($balance !== $entry->balance_after) {
            $checks['stock_running_balance_mismatches']++;
        }
    }
    if ($balance !== $medicine->quantity) {
        $checks['stock_ledger_mismatches']++;
    }
}
$checks['case_insensitive_email_duplicates'] = DB::table('users')->selectRaw('LOWER(email) as normalized_email, COUNT(*) as aggregate')->groupByRaw('LOWER(email)')->havingRaw('COUNT(*) > 1')->get()->count();
$checks['owners_missing_profile'] = DB::table('users as u')->leftJoin('owners as o', 'o.user_id', '=', 'u.id')->where('u.role', 'owner')->whereNull('o.id')->count();
$checks['vets_missing_profile'] = DB::table('users as u')->leftJoin('veterinarians as v', 'v.user_id', '=', 'u.id')->where('u.role', 'veterinarian')->whereNull('v.id')->count();
$checks['open_visits_without_active_vet'] = DB::table('appointments as a')->join('veterinarians as v', 'a.veterinarian_id', '=', 'v.id')->join('users as u', 'v.user_id', '=', 'u.id')->whereIn('a.status', ['pending', 'confirmed'])->where(fn ($q) => $q->where('u.active', false)->orWhere('u.role', '!=', 'veterinarian'))->count();
$applied = DB::table('migrations')->pluck('migration')->all();
$pending = array_values(array_filter(array_map(fn ($file) => basename($file, '.php'), glob(dirname(__DIR__, 2).'/database/migrations/*.php')), fn ($name) => ! in_array($name, $applied)));
$counts = [];
foreach (['users', 'owners', 'veterinarians', 'pets', 'appointments', 'medical_records', 'vaccinations', 'prescriptions', 'medicines', 'prescription_items', 'services', 'invoices', 'payments', 'inventory_transactions'] as $table) {
    $counts[$table] = DB::table($table)->count();
}
echo json_encode(['database_driver' => DB::connection()->getDriverName(), 'timezone' => config('app.timezone'), 'mail_transport' => config('mail.default'), 'pending_migrations' => $pending, 'counts' => $counts, 'integrity_checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit(array_sum($checks) > 0 || $pending !== [] ? 1 : 0);
