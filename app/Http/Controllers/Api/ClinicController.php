<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Veterinarian;
use App\Services\ClinicAccess;
use App\Services\ClinicCommands;
use App\Services\ClinicDeletion;
use App\Services\WorkspaceData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        app(ClinicAccess::class)->authorize($request->user(), 'dashboard');

        return response()->json(['data' => $request->user()->only(['id', 'name', 'email', 'role', 'active'])]);
    }

    public function index(Request $request, string $section): JsonResponse
    {
        $user = $request->user();
        if ($section === 'dashboard') {
            $data = app(WorkspaceData::class)->for($user, 'dashboard');

            return response()->json(['data' => $data['dashboardAlerts']]);
        }
        if ($section === 'settings') {
            app(ClinicAccess::class)->authorize($user, 'settings');

            return response()->json(['data' => DB::table('clinic_settings')->first()]);
        }
        $query = $this->query($user, $section);
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1|max:1000000',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'pet_id' => 'sometimes|integer|min:1',
            'status' => 'sometimes|string|in:pending,confirmed,completed,cancelled',
        ]);
        if (isset($filters['pet_id'])) {
            abort_unless(in_array($section, ['appointments', 'records', 'vaccinations']), 422, 'pet_id is not supported for this resource.');
            $query->where('pet_id', $filters['pet_id']);
        }
        if (isset($filters['status'])) {
            abort_unless($section === 'appointments', 422, 'status is only supported for appointments.');
            $query->where('status', $filters['status']);
        }

        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Request $request, string $section, int $id): JsonResponse
    {
        return response()->json(['data' => $this->query($request->user(), $section)->findOrFail($id)]);
    }

    public function store(Request $request, string $section, ClinicCommands $commands): JsonResponse
    {
        $created = $commands->store($request->user(), $section, $request->all());

        // Commands may be idempotent (payments) or create multiple related rows.
        return response()->json([
            'message' => 'Saved successfully.',
            'data' => $created ? $this->query($request->user(), $section)->findOrFail($created->getKey()) : DB::table('clinic_settings')->first(),
        ]);
    }

    public function update(Request $request, string $section, int $id, ClinicCommands $commands): JsonResponse
    {
        $commands->update($request->user(), $section, $id, $request->all());

        return response()->json(['message' => 'Updated successfully.']);
    }

    public function destroy(Request $request, string $section, int $id, ClinicDeletion $deletion): JsonResponse
    {
        $deletion->delete($request->user(), $section, $id);

        return response()->json(['message' => 'Deleted successfully.']);
    }

    /** @return Builder<*> */
    private function query(User $user, string $section): Builder
    {
        // Booking catalogs are readable by all clinic roles; writes retain admin checks.
        app(ClinicAccess::class)->authorize($user, in_array($section, ['services', 'veterinarians']) ? 'pets' : $section);
        $visits = Appointment::visibleTo($user)->select('appointments.id');
        $history = Appointment::clinicalHistoryVisibleTo($user)->select('appointments.id');

        return match ($section) {
            'pets' => Pet::query()
                ->when($user->role === 'owner', fn ($q) => $q->whereHas('owner', fn ($q) => $q->where('user_id', $user->id)))
                ->when($user->role === 'veterinarian', fn ($q) => $q->whereIn('id', Appointment::visibleTo($user)->where('status', '!=', 'cancelled')->select('pet_id'))),
            'appointments' => Appointment::visibleTo($user)->with(['pet', 'service']),
            'records' => MedicalRecord::whereIn('appointment_id', $history)->with(['prescription.items', 'vaccinations']),
            'vaccinations' => Vaccination::whereIn('medical_record_id', MedicalRecord::whereIn('appointment_id', $history)->select('id')),
            'prescriptions' => Prescription::whereIn('medical_record_id', MedicalRecord::whereIn('appointment_id', $history)->select('id'))->with('items'),
            'invoices' => Invoice::whereIn('appointment_id', $visits)->with('payments'),
            'services' => Service::query()->when($user->role !== 'admin', fn ($q) => $q->where('active', true)),
            'veterinarians' => Veterinarian::with('user:id,name')->whereHas('user', fn ($q) => $q->where('role', 'veterinarian')->when($user->role !== 'admin', fn ($q) => $q->where('active', true))),
            'owners' => Owner::with('user:id,name,email,active')->whereHas('user', fn ($q) => $q->where('role', 'owner')),
            'users' => User::query()->select(['id', 'name', 'email', 'role', 'active', 'email_verified_at'])->with(['owner', 'veterinarian']),
            'inventory' => Medicine::query(),
            'transactions' => InventoryTransaction::query(),
            'payments' => Payment::query(),
            default => abort(404),
        };
    }
}
