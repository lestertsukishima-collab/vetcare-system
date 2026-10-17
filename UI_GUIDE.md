# VetCare interface

The clinic workspace uses a shared sage and forest palette, self-hosted Instrument Sans fonts, responsive navigation, patient cards, and light/dark themes. The welcome page and authentication screens use the same visual direction. Account settings retain the existing Flux components with the updated brand and accent colors.

## Livewire interactions

- Main clinic links use `wire:navigate`. The overview refreshes every 60 seconds while visible.
- Search runs on the server after a 300 ms debounce and retains the current user’s access scope. Appointment status/date filters and inventory filters work without full reloads. Search and filters are reflected in the URL.
- Directories and histories show 12 entries per page. Stock and payment ledgers retain 30 entries per page with Livewire pagination.
- Creation, editing, rescheduling, stock adjustments, and payment forms submit through Livewire, display validation errors, retain failed input, and refresh the workspace after saving.
- Prescription and vaccine rows use Livewire actions and stable keys. Removing a row retains the remaining entries.
- Booking shows the selected service fee immediately. Final booking still checks availability and snapshots the fee on the server.
- Appointment confirmation and cancellation run through the existing clinical workflow. Cancellation asks for confirmation in the interface.
- All commands share `ClinicCommands` and `ClinicAccess` with the ordinary HTTP endpoints. Accounts, ownership checks, financial idempotency, and clinical transactions continue to apply to both entry points.

## Keyboard and accessibility

Use **Ctrl K** (or **Command K**) to open quick navigation. Type a page name, use arrow keys to move, and press Enter to open it. Escape closes the dialog. The mobile navigation supports Escape and keeps the workspace inactive while open. Skip navigation, visible focus, reduced motion, screen reader notices, and print styles are included.

The theme button stores its choice locally and shares the Flux appearance preference used by account settings. Save confirmations can be dismissed. Dashboard care reminders remain visible while their underlying conditions apply.

## Assets and verification

Clinic styles are in `public/clinic.css` and `public/clinic-responsive.css`; JavaScript is in `public/clinic.js`. All use cache-busted URLs. The authentication and settings styles use the existing Vite build. Font assets are local under `public/fonts`.

`ClinicLivewireTest` covers search/pagination, ownership checks, status transitions, booking conflicts, rescheduling, repeatable clinical rows, atomic completion, duplicate payment protection, filter behavior, permissions after role changes, history pagination, and administrator forms. Existing lifecycle, security, migration, and schema tests also run against the redesigned pages.

Automated checks verify rendered Laravel views and Livewire actions. An interactive browser was unavailable during this implementation, so viewport screenshots and a visual browser walkthrough remain unverified.

## Requirements review updates

Owners can provide optional phone/address when registering and update or clear these fields in account settings. Search reads visible content rather than serialized field names, accepts displayed invoice numbers, filters vaccination follow-ups with history, and limits queries to 150 characters. Upcoming appointments appear nearest first. Clinic access is rechecked after email verification or account changes, and malformed prescription/vaccine rows return validation errors.

## Vaccination reminders and dashboard notifications

The overview displays clickable, live counts for pets with vaccinations due within 30 days, overdue vaccinations, and appointments scheduled today. Administrators also see active medicines below minimum stock. Counts use the signed-in user's permitted patients and appointments. Owners see their own pets; veterinarians see the clinical history of patients assigned to them and only their own scheduled appointments.

Each pet is counted once per vaccination alert, even when multiple vaccines are due. The 30-day window includes today and day 30; overdue dates have a separate alert. Only the latest recorded dose of each vaccine for each pet creates a reminder. A later dose supersedes the earlier reminder, including when no next date is scheduled.

Vaccinations show cards with the pet/vaccine name, last administration date, next vaccination date, and a clear status: **Due today**, **Due within 30 days**, **Overdue**, **Upcoming**, or **No follow-up set**. The status dropdown and search filter current reminders and their matching history together. Resetting filters restores all historical doses.

Stock alerts use quantity strictly below the medicine's reorder level and exclude inactive medicines. Their link opens the matching **Below minimum (active)** filter. The existing **Low stock** filter still includes medicines at the reorder level. Today's appointment alert excludes cancellations, includes completed visits, and opens the **Scheduled** / **Today** filters.

Notifications refresh every 60 seconds while the overview is visible and after clinic changes are saved. All date boundaries follow `APP_TIMEZONE` (Asia/Manila by default). These are in-app reminders; no outgoing vaccination messages or scheduling service are required.

## Searchable patient medical history

Open **Medical records** and search by pet name, breed, owner, diagnosis, treatment, medicine, vaccine, or date. The matching patient cards open a complete patient timeline. A selector lists all matches when more than six patients match. Selecting a patient clears the search so their full history appears immediately; the search box then filters that patient's events. **Choose another patient** returns to the patient browser and recent records.

The profile shows the pet's breed, sex, calculated age, owner, birth date, weight, color, patient notes, and visit/vaccine counts. Age uses the clinic's date, with months or days for younger pets and an explicit unknown state when the birth date is missing.

The timeline runs newest first and combines clinical visits with vaccination events. Visits show diagnosis, treatment, symptoms, notes, and prescription details with the original medicine labels and supplied quantities. Vaccines appear on their administration dates, even when recorded at a later visit, with next-due dates, batches, and current/historical dose status. Twelve events appear per page; searching filters the complete timeline before pagination. Clearing search retains the selected patient and restores all their events.

**View medical history** on patient cards and **Medical history** on appointments open the patient's timeline directly. **Record examination** also carries the patient context, so veterinarians can review prior history beside the examination workflow. The selected patient and search are stored in the URL.

Owners can open only their pets. Veterinarians can open assigned patients and read those patients' earlier records from other veterinarians. Administrators can review all patients. Every Livewire refresh rechecks access, including when assignment changes during an open session. Patient selection does not change permissions to create or modify clinical records.

## Responsive layouts

The clinic uses a navigation drawer through 1024 CSS pixels, with a close button, Escape support, contained keyboard focus, and page-scroll locking. Returning to desktop width restores the permanent sidebar and active page focus. Navigation across Livewire pages resets drawer state.

Forms, prescription/vaccine repeaters, patient profiles, cards, pagination, and toolbars adapt to available space. Tablet summaries use two columns and phone forms use one. Long names, notes, and amounts wrap; narrow headers shorten the breadcrumb visually while retaining its full text. Touch devices get 44-pixel control targets and 16-pixel form text. Viewport safe areas and short landscape screens have dedicated spacing rules.

Wide tables, including reports and prescription tables, scroll within their panels. A hint appears only when horizontal scrolling is needed. Overflowing regions receive keyboard focus and an accessible label. ResizeObserver and Livewire's `morphed` hook update this behavior after resizing, filtering, expanding editors, and replacing table content. Inline table editors scale to the viewport. The welcome, sign-in/registration, and account settings layouts also have responsive width guards.

Run `npm run test:ui` for four JavaScript interaction regressions covering navigation focus/Escape, desktop resizing and Livewire navigation, table hints/keyboard access, and observer cleanup after table replacement. These tests use a browser API fixture; they do not emulate CSS layout. CSS parsing, production builds, Blade compilation, and the Laravel suite also pass. The connected browser inventory was empty, so screenshots and real-device viewport testing remain unverified.
