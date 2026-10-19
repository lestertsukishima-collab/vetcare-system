<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

class WorkspaceData
{
    public const PER_PAGE = 6;

    /** @return array<string, mixed> */
    public function formFor(User $user, string $section): array
    {
        app(ClinicAccess::class)->authorize($user, $section);
        $context = array_fill_keys(['appointments', 'pets', 'vets', 'services', 'medicines', 'owners', 'invoices'], collect());
        $context['clinic'] = DB::table('clinic_settings')->first();
        if ($section === 'appointments') {
            $context['pets'] = Pet::with('owner.user')->when($user->role === 'owner', fn ($q) => $q->whereHas('owner', fn ($q) => $q->where('user_id', $user->id)))->orderBy('name')->get();
            $context['vets'] = Veterinarian::with('user')->whereHas('user', fn ($q) => $q->where('role', 'veterinarian')->where('active', true))->get();
            $context['services'] = Service::orderBy('name')->get();
        }
        if ($section === 'pets' && $user->role === 'admin') {
            $context['owners'] = Owner::with('user')->whereHas('user', fn ($q) => $q->where('role', 'owner'))->get();
        }
        if ($section === 'records') {
            $context['appointments'] = Appointment::visibleTo($user)->with('pet')->where('status', 'confirmed')->orderBy('appointment_date')->get();
            $context['medicines'] = Medicine::orderBy('name')->get();
        }
        if ($section === 'payments') {
            $context['invoices'] = Invoice::with(['appointment.pet', 'payments'])->get();
        }
        if ($section === 'invoices') {
            $context['appointments'] = Appointment::visibleTo($user)->with(['pet', 'invoice'])->where('status', 'completed')->get();
        }

        return $context;
    }

    /** @return array<string, mixed> */
    public function for(User $user, string $section): array
    {
        app(ClinicAccess::class)->authorize($user, $section);
        $appointments = Appointment::visibleTo($user)->with(['pet.owner.user', 'veterinarian.user', 'service', 'medicalRecord.prescription.items.medicine', 'medicalRecord.vaccinations', 'invoice'])->orderByDesc('appointment_date')->orderByDesc('id')->get();
        $history = Appointment::clinicalHistoryVisibleTo($user)->whereHas('medicalRecord')->with(['pet.owner.user', 'veterinarian.user', 'service', 'medicalRecord.prescription.items.medicine', 'medicalRecord.vaccinations'])->orderByDesc('appointment_date')->orderByDesc('id')->get();
        $latestVaccinations = app(VaccinationSchedule::class)->latest($history);
        $medicines = $user->role !== 'owner' ? Medicine::orderBy('name')->orderBy('id')->get() : collect();
        $pets = Pet::with('owner.user')->when($user->role === 'owner', fn ($q) => $q->whereHas('owner', fn ($q) => $q->where('user_id', $user->id)))
            ->when($user->role === 'veterinarian', fn ($q) => $q->whereIn('id', Appointment::visibleTo($user)->where('status', '!=', 'cancelled')->select('pet_id')))->orderBy('name')->orderBy('id')->get();
        $invoices = in_array($user->role, ['admin', 'owner']) ? Invoice::with(['appointment.pet', 'payments.recorder'])->whereIn('appointment_id', Appointment::visibleTo($user)->select('id'))->latest()->orderByDesc('id')->get() : collect();

        return [
            'section' => $section, 'appointments' => $appointments, 'history' => $history, 'latestVaccinations' => $latestVaccinations, 'pets' => $pets, 'invoices' => $invoices,
            'vets' => Veterinarian::with('user')->whereHas('user', fn ($q) => $q->where('role', 'veterinarian'))->orderBy('id')->get(), 'services' => Service::orderBy('name')->orderBy('id')->get(),
            'medicines' => $medicines,
            'dashboardAlerts' => app(DashboardAlerts::class)->summary($user, $appointments, $latestVaccinations, $medicines),
            'owners' => $user->role === 'admin' ? Owner::with('user')->whereHas('user', fn ($q) => $q->where('role', 'owner'))->orderBy('id')->get() : collect(),
            'users' => $user->role === 'admin' ? User::with(['owner', 'veterinarian'])->orderBy('name')->orderBy('id')->get() : collect(),
            'transactions' => $section === 'transactions' ? InventoryTransaction::with(['medicine', 'user'])->latest('id')->paginate(self::PER_PAGE) : null,
            'payments' => $section === 'payments' ? Payment::with(['invoice.appointment.pet', 'recorder'])->latest()->orderByDesc('id')->paginate(self::PER_PAGE) : null,
            'clinic' => DB::table('clinic_settings')->first(),
        ];
    }
}
