# VetCare API v1

Base URL: `/api/v1`. This API is for clients on the same origin as VetCare. It uses the existing Fortify login session, including configured two-factor authentication. Sign in through `/login`; send the session cookie on API requests. Accounts must be active and email verified. No API keys, bearer tokens, migration, or extra package is required.

Email lookup and uniqueness are case insensitive, including existing mixed-case addresses and password resets. New and edited email addresses are stored in lowercase.

Send `Accept: application/json` and `Content-Type: application/json`. All `/api/*` errors return JSON even without an Accept header. Writes require the normal Laravel CSRF token (`X-CSRF-TOKEN` from the page's csrf-token meta tag, or `X-XSRF-TOKEN` from the URL-decoded XSRF-TOKEN cookie). Do not disable CSRF middleware. The existing `/logout` endpoint ends the session.

## Reads

`GET /me` returns the signed-in account's id, name, email, role, and active status. `GET /dashboard` returns clinic reminder counts. `GET /settings` returns clinic settings for administrators.

The following support `GET /{resource}` and `GET /{resource}/{id}`:

| Resource | Access |
| --- | --- |
| pets | Admin: all; owner: own; vet: patients assigned through non-cancelled visits |
| appointments | Admin: all; owner: own pets; vet: assigned visits |
| records, prescriptions, vaccinations | Own pets or assigned patients' full clinical history; admin: all |
| invoices | Admin: all; owner: own pets, including payment history |
| services, veterinarians | All clinic roles for booking; non-admins see active catalog entries |
| owners, users, inventory, transactions, payments | Admin only |

Lists use database pagination: `?page=1&per_page=25` (maximum 100 per page) and return `data`, `total`, `current_page`, `last_page`, and navigation URLs. Details return `{ "data": {...} }`. IDs outside the caller's scope return 404. Veterinarian booking entries include the veterinarian profile and user id/name, without account email or authentication fields.

Appointments accept `status=pending|confirmed|completed|cancelled`. Appointments, records, and vaccinations accept `pet_id`. Filters never expand the caller's access scope. Vaccination lists include the full dose history. Dashboard reminders use only the latest dose for each patient/vaccine.

## Writes

`POST /{resource}` supports pets, appointments, records, services, inventory, owners, veterinarians, users, invoices, payments, and settings. `PUT` or `PATCH /{resource}/{id}` supports pets, appointments, services, inventory, users, owners, and veterinarians. Updates require the fields shown below, as in the workspace forms. The API reuses the workspace command validation and permission rules. Success returns HTTP 200 with a `message`. POST also returns `data` containing the created resource and its ID (or the existing receipt/invoice for an idempotent retry, or the saved settings). Owner/veterinarian creations return the profile ID, matching their GET/PATCH/DELETE endpoints. Authentication secrets are excluded. Updates can be followed with GET to refresh data.

Appointment and payment timestamps accept clinic-local strings or ISO 8601 with an offset, for example `2026-10-20T02:00:00Z` means 10:00 AM in Asia/Manila. Values are converted to the configured clinic timezone and stored with second precision. Payment retries may use equivalent timestamps with a different offset or fractional seconds; the same UUID and equivalent saved payment details still return one receipt. JSON date serialization uses Laravel's UTC timestamps, so clients should format display times in the clinic timezone.

| Operation | Required fields |
| --- | --- |
| Create/edit pet | name, species, sex (`Male`, `Female`, `Unknown`); admin creation also requires owner_id |
| Book appointment | pet_id, veterinarian_id, service_id, appointment_date (future), reason |
| Confirm/cancel appointment | PATCH with status (`confirmed` or `cancelled`) |
| Reschedule appointment | PATCH with operation=`reschedule`, veterinarian_id, appointment_date |
| Record examination (assigned vet) | appointment_id, symptoms, diagnosis, treatment; optional notes, instructions, items, vaccinations |
| Create service | name, price; optional description; PATCH also requires active |
| Create medicine batch | name, unit, unit_price, reorder_level, expires_at, quantity |
| Edit medicine | name, unit, unit_price, reorder_level, expires_at, active |
| Adjust stock | PATCH inventory with operation=`stock`, quantity_change (nonzero integer), notes |
| Create account | name, email, password (12+ characters), role; optional phone, address, license_number, specialization; owners/veterinarians endpoints fix the created role |
| Edit account | PATCH users with name, email, active; optional role and profile fields |
| Edit owner/veterinarian | PUT or PATCH owners/veterinarians with name, email, active and optional profile fields; use the profile ID returned by GET, and the endpoint retains the profile's current role |
| Generate invoice | appointment_id (completed visit) |
| Record received payment | invoice_id, amount, method (`cash`, `bank_transfer`, `card`), paid_at, request_key (UUID); reference required for non-cash |
| Save settings | name; optional phone, address |

Examination `items` rows accept medicine_id, quantity, dosage, dispense_now (boolean). `vaccinations` rows accept vaccine_name, date_given, next_due_date, batch_number. Each list allows at most 20 rows. Clinical records, stock deductions, appointment completion, and invoicing remain atomic. Payments record money already received; retry with the same UUID and identical details to avoid duplicate receipts.

Example from an authenticated VetCare page:

```js
const response = await fetch('/api/v1/pets', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
  },
  body: JSON.stringify({ name: 'Milo', species: 'Cat', sex: 'Male' }),
});
const result = await response.json();
```

## Delete

`DELETE /{resource}/{id}` supports pets, services, inventory, users, owners, and veterinarians. It returns HTTP 200 with `message` on success, 404 for a missing/inaccessible record, or 422 with `errors.delete` when history prevents removal. Normal session, CSRF, verification, role, and rate-limit checks apply. Deletion is permanent, and the workspace requires confirmation.

| Resource | Deletion rule |
| --- | --- |
| pets | Admin or owning customer; no appointments, medical records, or vaccinations, including cancelled visits |
| services | Admin; never used by an appointment; otherwise set active=false |
| inventory | Admin; quantity is zero and no ledger or prescription references; otherwise set active=false |
| users | Admin; another non-admin account with no pets, appointments, payments recorded, or stock audit history |
| owners, veterinarians | Same account deletion rules, using the profile ID; account and both retained profiles are removed together |

Deleting an account also removes its database sessions and password reset tokens. Administrator accounts and the current account are protected. Existing historical profiles remain protected after role changes. Create a medicine with zero opening quantity to keep an unused catalog entry removable; positive opening stock creates an audit entry and prevents deletion even after stock reaches zero.

Appointments are cancelled through PATCH rather than deleted. Clinical records, prescriptions, vaccinations, invoices, payments, and stock transactions remain retained; DELETE for those resources returns 405 when the caller has access. Settings and reports do not have deletion operations.

## Errors and limits

401: no session; 403: inactive/unverified account or role restriction; 404: unknown/inaccessible resource; 405: unsupported HTTP method; 419: missing/expired CSRF token; 422: validation failure (`message` and field-keyed `errors`); 429: more than 120 requests per minute per authenticated user. Some workflow authorization failures return 403 for existing records the caller cannot modify.

Run `php artisan test --filter="ClinicApiTest|ClinicCrudTest"` for API authorization, CRUD lifecycles, ownership isolation, protected history, clinical history, booking conflicts, examination/invoice workflow, payment retries, pagination, validation, and CSRF checks.
