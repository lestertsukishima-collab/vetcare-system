<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Vaccination;
use Illuminate\Support\Collection;

class VaccinationSchedule
{
    public const FILTERS = ['due_soon', 'attention', 'overdue', 'due_today', 'upcoming', 'unscheduled'];

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, array{appointment: Appointment, vaccination: Vaccination, status: string}>
     */
    public function latest(Collection $appointments): Collection
    {
        $latest = [];
        foreach ($appointments as $appointment) {
            foreach ($appointment->medicalRecord->vaccinations ?? [] as $vaccination) {
                $key = $appointment->pet_id.':'.mb_strtolower(trim($vaccination->vaccine_name));
                $previous = $latest[$key]['vaccination'] ?? null;
                if (! $previous || $vaccination->date_given->gt($previous->date_given) ||
                    ($vaccination->date_given->eq($previous->date_given) && $vaccination->id > $previous->id)) {
                    $latest[$key] = ['appointment' => $appointment, 'vaccination' => $vaccination, 'status' => $this->status($vaccination)];
                }
            }
        }

        return collect(array_values($latest))->sortBy(fn ($row) => $row['vaccination']->next_due_date?->format('Y-m-d') ?? '9999-12-31')->values();
    }

    public function status(Vaccination $vaccination): string
    {
        $due = $vaccination->next_due_date;

        return match (true) {
            $due === null => 'unscheduled',
            $due->lt(today()) => 'overdue',
            $due->isToday() => 'due_today',
            $due->lte(today()->addDays(30)) => 'due_soon',
            default => 'upcoming',
        };
    }

    public function matchesStatus(Vaccination $vaccination, string $filter): bool
    {
        $status = $this->status($vaccination);

        return match ($filter) {
            'due_soon' => in_array($status, ['due_today', 'due_soon']),
            'attention' => in_array($status, ['overdue', 'due_today', 'due_soon']),
            'overdue', 'due_today', 'upcoming', 'unscheduled' => $status === $filter,
            default => true,
        };
    }
}
