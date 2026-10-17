# VetCare

Laravel veterinary management with Admin, Veterinarian, and Pet Owner workspaces. The application uses separate owner and veterinarian profiles, itemized prescriptions, vaccinations, invoices, payments, and an inventory ledger.

## Run locally

1. Use PHP 8.4.1 or newer (required by the installed dependencies). Configure `.env` for your database and set `APP_TIMEZONE=Asia/Manila`.
2. Run `php artisan migrate`.
3. Run `php artisan clinic:admin` and enter your administrator credentials. No default staff passwords are installed.
4. Run `npm install` and `npm run build` for the authentication screens. The clinic workspace also has its own stylesheet and small JavaScript file in `public`.
5. Open your Herd site and sign in. Admins should add services, veterinarians, and pet owners before booking visits. Owners can also self-register and verify their email.

## Workflows

- Admin: create and edit staff and owner accounts, change roles, deactivate accounts, register patients, edit services, add medicine batches, receive/adjust stock with a reason, inspect stock history, confirm/cancel/reschedule visits and reassign veterinarians, view invoices, record received payments, print reports, and save clinic settings.
- Veterinarian: view assigned patients and appointments, read those patients' prior clinical history from other veterinarians, confirm/cancel their own visits, and record symptoms, diagnosis, treatment, clinical notes, itemized prescriptions, and multiple vaccinations. Saving the record completes the visit, deducts dispensed stock, and creates the invoice in one database transaction. Reading a patient's history does not allow changing another veterinarian's appointments.
- Pet Owner: register/edit their pets, enter or update their own phone/address in registration and account settings, request/cancel/reschedule future appointments, view their pets' clinical and vaccination history, and check invoice balances and payment history.
- Appointments reserve a 30-minute slot for both the pet and veterinarian. Cancelled slots can be booked again. Bookings retain the service fee at booking time.
- Changing an appointment's time or veterinarian returns it to pending confirmation and preserves its original service name and fee. Closed visits and visits with medical records cannot be changed. A change that keeps the same time and veterinarian leaves confirmation intact.
- Inventory highlights low stock and expired medicine. Vaccination follow-ups use the latest dose for each pet and vaccine, so a newer dose supersedes an older follow-up. Full vaccination history remains visible, with administration dates and batch numbers.

Clinical records are entered once per confirmed appointment. Prescriptions can include multiple medicines, quantities, dosage instructions, and general instructions. Each item can be marked **Dispense now** or **Prescription only**. Prescription-only items do not deduct stock or add invoice charges, and can be prescribed when the clinic has no usable stock. Dispensing rejects expired or insufficient stock; inactive medicines cannot be prescribed. Items snapshot the medicine name, unit, and price. Bookings snapshot the service name and fee. Later catalog edits do not rewrite these historical details. Invoice totals use the booked service fee plus medicines actually dispensed. Payments cannot exceed the remaining balance. Payments record money received outside this application; this version does not charge a bank card or initiate transfers.

Each payment form carries a unique submission key. Retrying the same payment leaves one receipt; reusing that key with different payment details is rejected. Programmatic payment submissions must include a UUID `request_key`. Older receipts remain valid without a key.

New registrations always become Pet Owners. Only admins can create staff accounts. Deactivation preserves historical data, blocks password login, and ends existing authenticated sessions when they next access the application, including account settings. Account self-deletion is blocked for admins and for accounts linked to clinical records; a blocked deletion leaves the account signed in. Account settings are linked from the clinic workspace. Outgoing email must be configured for self-registration verification and password resets; admin-created accounts are verified when created.

Role changes retain existing owner/veterinarian profiles and their historical links. Only the current role controls access. At least one active administrator must remain, and veterinarians with pending or confirmed appointments must have those visits reassigned or cancelled before their account is deactivated or moved to another role. Payment and inventory audit history also prevent account self-deletion, including for former staff.

## MySQL

Create an empty database, then set these keys in `.env` using your own credentials:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vetcare
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Run `php artisan config:clear` and `php artisan migrate`. SQLite is also supported for local development and feature tests. If using SQLite locally, create `database/database.sqlite` before migrating. Do not run `migrate:fresh` against a database containing records you need to keep.

The second clinic migration upgrades the initial schema and carries over existing owner/veterinarian relationships, prescription notes, vaccination history, and opening stock balances. Legacy prescription text is retained in `medical_records.legacy_prescription`. See [database/SCHEMA.md](database/SCHEMA.md) for relationships. Invoices for older completed visits can be generated by an admin from the Invoices page. The initial schema did not store historical service prices; migration snapshots the current service price for those older bookings.

The third clinic migration adds service and medicine label snapshots. Existing rows are populated from the current catalog; original labels changed before this migration cannot be reconstructed.

The fourth migration records prescribed and dispensed quantities separately and adds unique payment submission keys. Existing prescription items retain their original dispensed quantities, and existing invoices and payment amounts stay unchanged.

The fifth migration applies the supplied core-field specification: pet weight (kg) and color, appointment_date, medical-record symptoms and notes with direct pet/veterinarian links, and vaccine_name/date_given/next_due_date with direct pet/veterinarian links. Existing appointment and vaccine dates and examination text are preserved through column renames. Clinical references are backfilled from their appointments. [database/SCHEMA.md](database/SCHEMA.md) lists every requested field and the retained supporting fields.

## Verification

Run `php artisan test`. Clinic tests cover ownership isolation, staff permissions, normalized profiles, scheduling conflicts, appointment changes, atomic medical records and stock movements, prescription-only items, prescription/vaccination visibility, invoice amounts, payment retries, overpayment rejection, role transitions, last-admin protection, inactive accounts, full patient history for assigned veterinarians, multiple vaccinations, follow-up supersession, malformed inputs, form input isolation, historical labels, migrations, and page rendering. A complete lifecycle test exercises all three roles and writes to all 14 requested tables.

The local `.tools/php` directory, when present, contains a downloaded development runtime used for verification. It is ignored by Git and is not part of the application deployment.

## Interface and Livewire

The redesigned interface is applied to the clinic workspace, welcome page, and authentication screens. Refresh an open clinic tab to load the updated assets. Navigation, live search/filters, paginated directories, and clinic forms use Livewire. Use Ctrl K (Command K on macOS) for quick navigation. The theme button shares the Flux appearance preference. See [UI_GUIDE.md](UI_GUIDE.md) for details and verification limits.

## Sample clinic and factories

Run `php artisan db:seed` in the local development environment to add the complete sample clinic. If PHP is not on PATH, run `.\.tools\php\php.exe artisan db:seed`. The default DatabaseSeeder calls SampleClinicSeeder. It adds six demo accounts, ten pets, 24 appointments, clinical records, vaccinations, invoices, received payments, and stock history without resetting existing tables. Reruns preserve sample data and passwords. See [SAMPLE_DATA.md](SAMPLE_DATA.md) for sign-in credentials, factory states, and examples.

The clinic workspace requires a verified email on initial visits and subsequent Livewire actions. Owners can still use account settings to complete contact details and request verification. Search matches visible names, contact details, clinical content, catalog fields, and displayed invoice numbers; current vaccination follow-ups filter with history. Upcoming visits are shown nearest first. Invalid workspace query types and malformed clinical row data are handled without rendering errors.

## In-app care reminders

The overview shows live vaccination, stock, and today's appointment notifications. Vaccination cards show last/next dates and current status. Click an alert to open its matching filtered list. Reminder counts follow the existing owner/veterinarian access scopes; stock alerts are administrator-only. They update on the visible dashboard every 60 seconds and after saved clinic changes.

Set `APP_TIMEZONE` to the clinic's timezone (default `Asia/Manila`) so today's appointments and due dates use the intended local calendar. Vaccinations due from today through 30 days ahead count once per pet; overdue vaccinations have a separate count. Only the latest dose per pet/vaccine determines the next reminder. Active medicines trigger the minimum-stock alert when quantity is strictly below the reorder level.

This feature needs no additional migration, mail setup, queue worker, or cron job. It displays reminders inside the authenticated system. Email delivery for registration verification and password resets remains separate.

## Searchable patient history

The **Medical records** page includes a searchable patient browser and individual medical timelines. Patient cards and appointment rows link directly to `/clinic/records?patient=ID`. Examination links include the patient context. The profile calculates age from the existing birth date; the timeline combines clinical visits, prescription details, and vaccinations using their actual care dates. Searching and pagination use Livewire and the current user's access scope.

No migration or reseeding is required. Existing records populate the feature immediately. Owners see their own patients, veterinarians see assigned patients and their prior clinical history, and administrators see all patients. A missing or inaccessible patient returns a controlled not-found response. See [UI_GUIDE.md](UI_GUIDE.md) for search behavior and navigation.
