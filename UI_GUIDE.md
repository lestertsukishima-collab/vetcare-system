# VetCare interface

The clinic workspace uses a shared sage and forest palette, self-hosted Instrument Sans fonts, responsive navigation, patient cards, and light/dark themes. The welcome page and authentication screens use the same visual direction. Account settings retain the existing Flux components with the updated brand and accent colors.

## Bootstrap integration

Bootstrap 5.3.8 is installed through npm with Popper and Sass. Vite builds `resources/scss/clinic-bootstrap.scss` and `resources/js/clinic-bootstrap.js`; the workspace loads these assets locally. Run `npm install` and `npm run build` when deploying or changing the assets.

Vite Plus and its Vite core alias are pinned to 0.3.3, with an npm override keeping plugin imports on the same core. Update those versions together when upgrading the build tools.

The workspace uses Bootstrap's responsive dashboard grid, form controls and validation states, scrolling tables, buttons, alerts, spinners, tooltips, toast notifications, and delete confirmation modal. Colors follow VetCare's existing light/dark theme. Component styles are scoped beneath `.vetcare-bootstrap`, and Bootstrap Reboot is excluded so Flux and Tailwind authentication screens keep their existing styles.

Deletion requires an explicit confirmation before calling the existing Livewire `deleteRecord` action. Duplicate submissions are blocked while the request is pending. Backend permissions and clinic-history retention rules still apply. Notification text is rendered as plain text, and Bootstrap pauses toast dismissal while the user interacts with it. Tooltips support hover and keyboard focus. Livewire navigation disposes overlays and tooltip instances; morph hooks refresh dynamic controls.

Keep future Bootstrap styles in the scoped Sass entry and import only the plugins needed in the workspace. Run `npm run test:ui` for interaction tests covering confirmation/cancellation, duplicate deletion protection, notification handling, overlay cleanup, navigation, table accessibility, and email verification. These tests use browser API fixtures; screenshots and real browser layout testing remain unverified because no connected browser was available.

## Livewire interactions

The owner journey is: register and verify email, add a pet, and book a pending appointment. An administrator or the assigned veterinarian confirms the visit. The assigned veterinarian records the examination findings, diagnosis, and treatment, optionally adding a prescription, vaccinations, or both. Saving the examination completes the appointment automatically. The owner can then open the pet's updated medical history, prescriptions, and vaccination records. `OwnerJourneyTest` verifies self-registration through owner-visible results for both confirmation roles and for prescription-only and vaccination-only visits.

- Main clinic links use `wire:navigate`. The overview refreshes every 60 seconds while visible.
- The topbar's **Refresh workspace** button updates the current section without reloading the page. `resources/js/clinic-events.js` uses `addEventListener` for clicks, reconnection, and navigation; its `async` refresh function uses `await` for the existing Livewire server action. It shows loading and result feedback, prevents overlapping refreshes, and ignores stale completions after navigation. Reconnection refreshes the current workspace automatically.
- Search runs on the server after a 300 ms debounce and retains the current user’s access scope. Appointment status/date filters and inventory filters work without full reloads. Search and filters are reflected in the URL.
- Directories, appointment lists, medical histories, stock transactions, and payments show six records per page with Livewire pagination: 1–6, 7–12, 13–18, 19–24, and so on. Patient search cards and vaccination follow-ups have independent six-record pagination. Search and filter changes reset the relevant pages; dashboard totals continue to use all permitted records.
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

Account settings links and links back to the clinic workspace use full page navigation because these layouts have separate stylesheets. This lets the browser load the correct styles before painting and clears the previous layout's global styles. Navigation within the clinic and between settings tabs still uses Livewire. `SettingsNavigationTest` covers these layout boundaries.

## Printing invoices and clinic reports

**Print report** on Invoices or Reports opens an authenticated print preview in the same tab. It opens the browser print dialog after styles and fonts are ready; **Print / Save PDF** can open it again. **Back** returns to the previous section, invoice search, and page.

Invoice printing includes every invoice matching the current search across all pages, complete payment histories, and totals calculated in integer cents. Owners receive only their own invoices; administrators receive all invoices. Clinic reports remain administrator-only and show all-time activity. The standalone preview uses light paper styles, A4 margins, wrapping table content, and repeating table headings. Workspace controls and pagination are excluded.

`ClinicPrintTest` verifies complete multi-page output, filters, totals, payment history, permissions, malformed queries, escaped input, and Livewire redirects. Frontend tests verify readiness, duplicate-dialog prevention, manual retry, and error handling. A connected browser was unavailable, so Edge's native print preview, actual PDF pagination, and physical printer output have not been visually verified.

## Profile photos

Account avatars, veterinarian booking photos, pet photos, and upload previews display as circles at every size. Initials and paw fallbacks use the same circular frames. Uploaded files retain a 1:1 ratio for consistent cropping.

Every account role (pet owner, veterinarian, and administrator) can upload, replace, or remove its own photo under **Account settings → Profile → Profile photo**. Choose a JPG, PNG, or WebP image of at most 2 MB, adjust the circular preview using zoom and horizontal/vertical position, and select **Save photo**. Choosing a new image leaves the saved photo in place until saving succeeds; cancelling discards only the selection.

The browser creates a 512 × 512 PNG from the selected 1:1 crop. The server independently validates image content, allowed formats, the 2 MB limit, and exact square dimensions. Saved photos appear in the workspace account menu and the desktop/mobile settings account menus; removing a photo restores initials. Replacements use a fresh URL and remove the previous file after the database update succeeds.

Photos use the private `local` storage disk and authenticated routes; no public storage link or mail/environment changes are needed. Owners' and administrators' photos are available only to their own accounts. Active veterinarians' photos can also be viewed by verified clinic users through the booking photo route. Run migrations when deploying the new `users.profile_photo_path` column. `ProfilePhotoTest` covers all roles, upload/replacement/removal, size boundaries, square dimensions, invalid formats, and photo access. Frontend tests cover cropping, cancellation, upload failures, duplicate requests, and avatar updates.

Booking and rescheduling use selectable veterinarian cards with a circular photo (or initials), full name, and specialization. Only active veterinarian accounts appear. Cards use native required radio inputs, support keyboard selection, and retain the chosen doctor after validation errors. The same uploaded profile photo is used; replacements receive a new booking URL, and removed or inactive doctors' photo URLs return 404. `VeterinarianBookingPhotosTest` covers photo delivery, privacy, card selection, booking, and rescheduling.

## Pet photos for appointed veterinarians

Owners open **My pets → Manage pet photo** on a saved pet's card to upload, replace, or remove its photo. Administrators can manage pet photos from the patient directory too. The shared cropper creates a 512 × 512 square image, with the same JPG/PNG/WebP selection and 2 MB limit as account photos. Saving a photo updates that pet's visible avatars immediately. Pets without a photo show the paw icon.

Pet photos appear in appointment rows (including dashboard previews), patient cards, medical history search results, and the selected patient's history profile. A veterinarian can view a photo only when that veterinarian has an appointment for the pet. Access follows appointment reassignment. Owners see their own pets and administrators see all pets; veterinarians cannot change these photos. Files stay on private storage and photo responses use `no-store` so access is checked again when requested. The new `pets.photo_path` migration is required when deploying this feature.

`PetPhotosTest` covers owner/admin uploads, replacement/removal, appointed-doctor views, unrelated-account denial, reassignment, role changes, locked pet IDs, size/dimension validation, and private path protection. The shared frontend tests cover pet-specific uploads, independent crop editors, avatar updates, and paw fallbacks.

`ClinicLivewireTest` covers search/pagination, ownership checks, status transitions, booking conflicts, rescheduling, repeatable clinical rows, atomic completion, duplicate payment protection, filter behavior, permissions after role changes, history pagination, and administrator forms. Existing lifecycle, security, migration, and schema tests also run against the redesigned pages.

Automated checks verify rendered Laravel views and Livewire actions. An interactive browser was unavailable during this implementation, so viewport screenshots and a visual browser walkthrough remain unverified.

## Requirements review updates

Login sessions use Laravel's `AuthenticateSession` middleware across web, Livewire, and session-authenticated API requests. A password change or reset makes established older sessions fail authentication on their next request; the session changing its password keeps working. Logout clears session data and rotates the CSRF token. Inactive accounts continue to be signed out by `EnsureActiveAccount`. `SessionLifecycleTest` covers stale-session rejection, authenticated API writes, current-session continuity, and logout.

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

The timeline runs newest first and combines clinical visits with vaccination events. Visits show diagnosis, treatment, symptoms, notes, and prescription details with the original medicine labels and supplied quantities. Vaccines appear on their administration dates, even when recorded at a later visit, with next-due dates, batches, and current/historical dose status. Six events appear per page; searching filters the complete timeline before pagination. Clearing search retains the selected patient and restores all their events.

**View medical history** on patient cards and **Medical history** on appointments open the patient's timeline directly. **Record examination** also carries the patient context, so veterinarians can review prior history beside the examination workflow. The selected patient and search are stored in the URL.

Owners can open only their pets. Veterinarians can open assigned patients and read those patients' earlier records from other veterinarians. Administrators can review all patients. Every Livewire refresh rechecks access, including when assignment changes during an open session. Patient selection does not change permissions to create or modify clinical records.

## Responsive layouts

The clinic uses a navigation drawer through 1024 CSS pixels, with a close button, Escape support, contained keyboard focus, and page-scroll locking. Returning to desktop width restores the permanent sidebar and active page focus. Navigation across Livewire pages resets drawer state.

Forms, prescription/vaccine repeaters, patient profiles, cards, pagination, and toolbars adapt to available space. Tablet summaries use two columns and phone forms use one. Long names, notes, and amounts wrap; narrow headers shorten the breadcrumb visually while retaining its full text. Touch devices get 44-pixel control targets and 16-pixel form text. Viewport safe areas and short landscape screens have dedicated spacing rules.

Wide tables, including reports and prescription tables, scroll within their panels. A hint appears only when horizontal scrolling is needed. Overflowing regions receive keyboard focus and an accessible label. ResizeObserver and Livewire's `morphed` hook update this behavior after resizing, filtering, expanding editors, and replacing table content. Inline table editors scale to the viewport. The welcome, sign-in/registration, and account settings layouts also have responsive width guards.

Run `npm run test:ui` for JavaScript interaction regressions covering navigation focus/Escape, desktop resizing and Livewire navigation, table hints/keyboard access, and observer cleanup after table replacement, alongside the Bootstrap and email verification checks described above. These tests use browser API fixtures; they do not emulate CSS layout. The connected browser inventory was empty, so screenshots and real-device viewport testing remain unverified.
