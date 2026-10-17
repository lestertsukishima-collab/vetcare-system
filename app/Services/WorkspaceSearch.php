<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Owner;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Veterinarian;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class WorkspaceSearch
{
    public function matches(string $section, Model $row, string $needle): bool
    {
        $fields = match (true) {
            $row instanceof Pet => $this->patientFields($row),
            $row instanceof Appointment => $this->appointmentFields($row, $section),
            $row instanceof Owner => [$row->user->name, $row->user->email, $row->user->role, $row->phone, $row->address, $row->user->active ? 'Active' : 'Inactive'],
            $row instanceof Veterinarian => [$row->user->name, $row->user->email, $row->user->role, $row->license_number, $row->specialization, $row->user->active ? 'Active' : 'Inactive'],
            $row instanceof User => [$row->name, $row->email, $row->role, $row->active ? 'Active' : 'Inactive', $row->role === 'owner' ? [$row->owner?->phone, $row->owner?->address] : ($row->role === 'veterinarian' ? [$row->veterinarian?->license_number, $row->veterinarian?->specialization] : [])],
            $row instanceof Service => [$row->name, $row->description, $row->price, $row->active ? 'Active' : 'Inactive'],
            $row instanceof Medicine => [$row->name, $row->quantity, $row->unit, $row->unit_price, $row->reorder_level, $row->expires_at->format('M d, Y'), $row->expires_at->toDateString(), $row->active ? 'Active' : 'Inactive'],
            $row instanceof Invoice => $this->invoiceFields($row),
            default => [],
        };

        return $this->contains($fields, $needle);
    }

    public function matchesVaccination(Appointment $appointment, Vaccination $vaccination, string $needle): bool
    {
        return $this->contains([$appointment->pet->name, $appointment->pet->owner->user->name, $appointment->veterinarian->user->name, $this->vaccinationFields($vaccination)], $needle);
    }

    public function matchesHistoryEvent(Appointment $appointment, ?Vaccination $vaccination, string $needle): bool
    {
        $fields = [$this->patientFields($appointment->pet), $appointment->veterinarian->user->name, $appointment->service_name ?? $appointment->service->name];
        if ($vaccination) {
            $fields[] = $this->vaccinationFields($vaccination);
        } else {
            $record = $appointment->medicalRecord;
            $fields[] = [$appointment->appointment_date->format('M d, Y'), $appointment->appointment_date->toDateString(), 'Record #'.$record?->id, $record?->symptoms, $record?->diagnosis, $record?->treatment, $record?->notes];
            if ($record?->prescription) {
                $fields[] = ['Prescription #'.$record->prescription->id, $record->prescription->instructions];
                foreach ($record->prescription->items as $item) {
                    $fields[] = [$item->medicine_name ?? $item->medicine->name, $item->medicine_unit ?? $item->medicine->unit, $item->quantity, $item->dispensed_quantity, $item->dosage];
                }
            }
        }

        return $this->contains($fields, $needle);
    }

    /** @return array<array-key, mixed> */
    private function patientFields(Pet $pet): array
    {
        return [$pet->name, $pet->species, $pet->breed, $pet->sex, $pet->weight, $pet->color, $pet->notes, $pet->birth_date?->format('M d, Y'), $pet->birth_date?->toDateString(), $pet->owner->user->name];
    }

    /** @return array<array-key, mixed> */
    private function appointmentFields(Appointment $visit, string $section): array
    {
        $fields = [$visit->pet->name, $visit->pet->species, $visit->pet->owner->user->name, $visit->veterinarian->user->name, $visit->service_name ?? $visit->service->name];
        if ($section === 'appointments') {
            return [...$fields, $visit->reason, $visit->status, $visit->service_fee, $visit->appointment_date->format('M d, Y h:i A'), $visit->appointment_date->toDateString()];
        }
        $record = $visit->medicalRecord;
        if (! $record) {
            return $fields;
        }
        $fields[] = $record->created_at?->format('M d, Y');
        $fields[] = 'Record #'.$record->id;
        if ($section === 'records') {
            $fields[] = $this->patientFields($visit->pet);
            $fields[] = [$visit->appointment_date->format('M d, Y'), $visit->appointment_date->toDateString()];
            $fields[] = [$record->symptoms, $record->diagnosis, $record->treatment, $record->notes];
        }
        if ($section !== 'vaccinations' && $record->prescription) {
            $fields[] = 'Prescription #'.$record->prescription->id;
            $fields[] = $record->prescription->instructions;
            foreach ($record->prescription->items as $item) {
                $fields[] = [$item->medicine_name ?? $item->medicine->name, $item->medicine_unit ?? $item->medicine->unit, $item->quantity, $item->dispensed_quantity, $item->dosage];
            }
        }
        if ($section !== 'prescriptions') {
            foreach ($record->vaccinations as $vaccination) {
                $fields[] = $this->vaccinationFields($vaccination);
            }
        }

        return $fields;
    }

    /** @return array<array-key, mixed> */
    private function vaccinationFields(Vaccination $vaccination): array
    {
        return [$vaccination->vaccine_name, $vaccination->batch_number, $vaccination->date_given->format('M d, Y'), $vaccination->date_given->toDateString(), $vaccination->next_due_date?->format('M d, Y'), $vaccination->next_due_date?->toDateString()];
    }

    /** @return array<array-key, mixed> */
    private function invoiceFields(Invoice $invoice): array
    {
        $fields = ['INV-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT), $invoice->appointment->pet->name, $invoice->appointment->appointment_date->format('M d, Y'), $invoice->due_date->format('M d, Y'), $invoice->total, $invoice->service_amount, $invoice->medicine_amount, number_format($invoice->balanceCents() / 100, 2), $invoice->balanceCents() === 0 ? 'Paid' : ($invoice->due_date->lt(today()) ? 'Overdue' : 'Unpaid partial')];
        foreach ($invoice->payments as $payment) {
            $fields[] = [$payment->paid_at->format('M d, Y'), $payment->amount, str_replace('_', ' ', $payment->method), $payment->reference];
        }

        return $fields;
    }

    /** @param array<array-key, mixed> $fields */
    private function contains(array $fields, string $needle): bool
    {
        $values = array_filter(Arr::flatten($fields), is_scalar(...));

        return $needle === '' || str_contains(mb_strtolower(implode(' ', $values)), mb_strtolower($needle));
    }
}
