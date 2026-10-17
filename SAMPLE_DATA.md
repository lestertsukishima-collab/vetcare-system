# Sample clinic data and factories

## Load the sample clinic

With the application configured for a local development database and its migrations applied, run:

```powershell
php artisan db:seed
```

Or run the sample seeder directly:

```powershell
php artisan db:seed --class=SampleClinicSeeder
```

If PHP is not on your terminal's PATH, this workspace also has a local PHP runtime:

```powershell
.\.tools\php\php.exe artisan db:seed
```

Seeding adds sample records without clearing the database. The sample seeder runs only in `local` and `testing` environments. No database reset is required.

## Demo sign-in

All six accounts are active and email-verified. Their initial password is **`VetCareDemo!2026`**.

| Role | Email | Name |
| --- | --- | --- |
| Admin | `admin@vetcare.test` | Demo Administrator |
| Veterinarian | `vet@vetcare.test` | Dr. Sofia Reyes (Demo) |
| Veterinarian | `vet2@vetcare.test` | Dr. Daniel Cruz (Demo) |
| Pet Owner | `owner@vetcare.test` | Alex Santos (Demo) |
| Pet Owner | `owner2@vetcare.test` | Jamie Garcia (Demo) |
| Pet Owner | `owner3@vetcare.test` | Morgan Rivera (Demo) |

## Included records

| Data | Count |
| --- | ---: |
| Users / owner profiles / veterinarian profiles | 6 / 3 / 2 |
| Pets with species, breed, sex, birth date, weight and color | 10 |
| Appointments | 24 |
| Completed / pending / confirmed / cancelled visits | 12 / 5 / 5 / 2 |
| Medical records / prescriptions / prescription items | 12 / 12 / 16 |
| Vaccinations | 6 |
| Services / medicines | 5 / 6 |
| Invoices / payments | 12 / 8 |
| Inventory transactions | 21 |

Appointments span past visits and upcoming dates. Vaccinations include a superseded historical dose, a current overdue follow-up, and upcoming follow-ups. Four invoices are paid, four partially paid, and four unpaid. Inventory includes an opening balance, a receipt, an adjustment, dispensing, low stock, an expired batch, and an inactive catalog item.

Completed visits use `ClinicWorkflow`, so prescription-only items do not reduce stock or add charges. Dispensed items create stock movements and invoice charges. The clinical text and product names are fictional demonstration content.

Running the seeder again leaves the existing sample dataset and passwords unchanged. If only some reserved demo emails already exist, seeding stops before inserting anything. Existing clinic settings are retained. The entire initial dataset is created in a database transaction.

Dates are relative to the first run. Rerunning does not move or rebuild existing sample visits.

## Use the factories

All 14 domain models support `Model::factory()`. These examples can be used in tests or Laravel Tinker:

```php
use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;

$admin = User::factory()->admin()->create();
$vet = User::factory()->veterinarian()->create();
$owner = User::factory()->owner()->create();

// User model events create their owner/veterinarian profiles automatically.
$pet = Pet::factory()->cat()->for($owner->owner)->create();
$service = Service::factory()->create(['name' => 'Sample consultation', 'price' => 500]);
$visit = Appointment::factory()
    ->for($pet)
    ->for($vet->veterinarian)
    ->for($service)
    ->confirmed()
    ->create(['appointment_date' => now()->addDays(2)->setTime(10, 0)]);

$lowStock = Medicine::factory()->lowStock()->create();
$expired = Medicine::factory()->expired()->create();
```

| Factory | Useful states / behavior |
| --- | --- |
| `UserFactory` | `owner()`, `veterinarian()`, `admin()`, `inactive()`, `unverified()`, `withTwoFactor()` |
| `OwnerFactory` / `VeterinarianFactory` | Create a matching account without duplicating its automatic profile |
| `PetFactory` | `cat()`; dog by default |
| `AppointmentFactory` | `confirmed()`, `completed()`, `cancelled()`; pending by default; snapshots service name/fee |
| `MedicalRecordFactory` | Creates a completed appointment and derives patient/veterinarian links |
| `VaccinationFactory` | Derives links and administration date from its medical record's appointment |
| `PrescriptionFactory` | Creates its medical record unless provided with `for($record, 'medicalRecord')` |
| `PrescriptionItemFactory` | Prescription-only by default; snapshots medicine name, unit and price |
| `MedicineFactory` | `lowStock()`, `expired()`, `inactive()`; records opening stock after creation |
| `ServiceFactory` | `inactive()`; active by default |
| `InvoiceFactory` | Creates a completed visit with a record and calculates its totals |
| `PaymentFactory` | Partial cash receipt by default; `fullBalance()` settles the remaining balance |
| `InventoryTransactionFactory` | Creates a stock receipt and updates the medicine's balance |

Factories create individual fixtures and allow custom attribute overrides. Appointment states set the visit status; `completed()` alone does not perform an examination or generate an invoice. Use `ClinicWorkflow` when exercising booking conflicts, completion, dispensing, payment limits, or other business actions; the sample seeder follows this approach.

Reuse an existing account's `$user->owner` or `$user->veterinarian` profile instead of asking its profile factory to create a second one. A medicine factory's opening ledger entry has no recording user unless you assign one. The sample seeder attributes its inventory movements to the demo administrator.

The tests in `ClinicSeederFactoryTest` verify the factories, sample data, invoice and stock reconciliation, idempotence, collision handling, and populated screens in an isolated SQLite database.
