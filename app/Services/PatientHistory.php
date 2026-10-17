<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\Vaccination;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** @phpstan-type HistoryEvent array{key: string, type: string, date: CarbonInterface, appointment: Appointment, vaccination: Vaccination|null} */
class PatientHistory
{
    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, HistoryEvent>
     */
    public function timeline(Collection $appointments): Collection
    {
        $events = [];
        foreach ($appointments as $appointment) {
            $record = $appointment->medicalRecord;
            if (! $record) {
                continue;
            }
            $events[] = ['key' => 'visit-'.$appointment->id, 'type' => 'visit', 'date' => $appointment->appointment_date, 'appointment' => $appointment, 'vaccination' => null];
            foreach ($record->vaccinations as $vaccination) {
                $events[] = ['key' => 'vaccination-'.$vaccination->id, 'type' => 'vaccination', 'date' => $vaccination->date_given, 'appointment' => $appointment, 'vaccination' => $vaccination];
            }
        }

        return collect($events)->sortBy([['date', 'desc'], ['key', 'desc']])->values();
    }

    public function age(Pet $pet): string
    {
        $birthDate = $pet->birth_date;
        if (! $birthDate || $birthDate->isFuture()) {
            return 'Age unknown';
        }
        $years = (int) $birthDate->diffInYears(today());
        if ($years > 0) {
            return $years.' '.($years === 1 ? 'year' : 'years').' old';
        }
        $months = (int) $birthDate->diffInMonths(today());
        if ($months > 0) {
            return $months.' '.($months === 1 ? 'month' : 'months').' old';
        }
        $days = (int) $birthDate->diffInDays(today());

        return $days === 0 ? 'Born today' : $days.' '.($days === 1 ? 'day' : 'days').' old';
    }
}
