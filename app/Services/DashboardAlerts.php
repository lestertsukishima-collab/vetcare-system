<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Support\Collection;

class DashboardAlerts
{
    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, array{appointment: Appointment, vaccination: Vaccination, status: string}>  $vaccinations
     * @param  Collection<int, Medicine>  $medicines
     * @return array{pets_due_soon: int, pets_overdue: int, medicines_below_minimum: ?int, appointments_today: int}
     */
    public function summary(User $user, Collection $appointments, Collection $vaccinations, Collection $medicines): array
    {
        return [
            'pets_due_soon' => $vaccinations->filter(fn ($row) => in_array($row['status'], ['due_today', 'due_soon']))->pluck('vaccination.pet_id')->unique()->count(),
            'pets_overdue' => $vaccinations->where('status', 'overdue')->pluck('vaccination.pet_id')->unique()->count(),
            'medicines_below_minimum' => $user->role === 'admin' ? $medicines->filter(fn (Medicine $medicine) => $medicine->active && $medicine->quantity < $medicine->reorder_level)->count() : null,
            'appointments_today' => $appointments->filter(fn (Appointment $visit) => $visit->status !== 'cancelled' && $visit->appointment_date->isToday())->count(),
        ];
    }
}
