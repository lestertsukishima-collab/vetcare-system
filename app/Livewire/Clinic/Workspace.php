<?php

namespace App\Livewire\Clinic;

use App\Models\User;
use App\Services\ClinicAccess;
use App\Services\ClinicDeletion;
use App\Services\ClinicWorkflow;
use App\Services\PatientHistory;
use App\Services\VaccinationSchedule;
use App\Services\WorkspaceData;
use App\Services\WorkspaceSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Workspace extends Component
{
    use WithPagination;

    #[Locked]
    public string $section = 'dashboard';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $patient = '';

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->search = mb_substr($this->search, 0, 150);
        }
        if ($property === 'patient') {
            $this->reset('search', 'status', 'date');
        }
        if (in_array($property, ['search', 'status', 'date', 'patient'])) {
            $this->resetPage();
        }
    }

    public function selectPatient(string $id): void
    {
        abort_unless($this->section === 'records', 404);
        $this->patient = $id;
        $this->updated('patient');
    }

    public function clearPatient(): void
    {
        $this->reset('patient');
        $this->clearFilters();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'date');
        $this->resetPage();
    }

    #[On('clinic-saved')]
    public function refreshWorkspace(): void {}

    public function changeStatus(int $id, string $status): void
    {
        $user = $this->actor();
        app(ClinicAccess::class)->authorize($user, $this->section);
        validator(['status' => $status], ['status' => ['required', Rule::in(['confirmed', 'cancelled'])]])->validate();
        app(ClinicWorkflow::class)->status($user, $id, $status);
        $this->dispatch('clinic-toast', message: $status === 'confirmed' ? 'Appointment confirmed.' : 'Appointment cancelled.');
    }

    public function deleteRecord(int $id): void
    {
        $this->resetValidation();
        app(ClinicDeletion::class)->delete($this->actor(), $this->section, $id);
        $this->resetPage();
        $this->dispatch('clinic-saved');
        $this->dispatch('clinic-toast', message: 'Deleted successfully.');
    }

    private function actor(): User
    {
        return User::findOrFail(auth()->id());
    }

    public function render(): View
    {
        $data = app(WorkspaceData::class)->for($this->actor(), $this->section);
        $key = match ($this->section) {
            'pets' => 'pets', 'appointments' => 'appointments', 'records', 'prescriptions', 'vaccinations' => 'history',
            'services' => 'services', 'inventory' => 'medicines', 'users' => 'users', 'owners' => 'owners', 'veterinarians' => 'vets', 'invoices' => 'invoices', default => null,
        };
        $data['pagination'] = null;
        $data['totalResults'] = $key ? $data[$key]->count() : 0;
        $needle = mb_strtolower(trim(mb_substr($this->search, 0, 150)));
        $search = app(WorkspaceSearch::class);
        $vaccinations = app(VaccinationSchedule::class);
        $data['vaccinationStatusFiltered'] = $this->section === 'vaccinations' && in_array($this->status, VaccinationSchedule::FILTERS, true);
        $data['vaccinationFollowUps'] = $data['latestVaccinations']->filter(fn ($row) => $search->matchesVaccination($row['appointment'], $row['vaccination'], $needle) && ($this->section !== 'vaccinations' || $vaccinations->matchesStatus($row['vaccination'], $this->status)));
        $data['filteredVaccinationIds'] = $data['vaccinationFollowUps']->pluck('vaccination.id')->all();
        $matchingRecords = $data['vaccinationFollowUps']->pluck('vaccination.medical_record_id')->all();
        $filterVaccinationStatus = $data['vaccinationStatusFiltered'];
        $data['selectedPatient'] = null;
        if ($this->section === 'records') {
            $matchingPetIds = $data['history']->filter(fn ($visit) => $search->matches('records', $visit, $needle))->pluck('pet_id')->all();
            $data['historyPatients'] = $data['pets']->filter(fn ($pet) => $search->matches('pets', $pet, $needle) || in_array($pet->id, $matchingPetIds));
            if ($this->patient !== '') {
                abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $this->patient) === 1, 404);
                $data['selectedPatient'] = $data['pets']->firstWhere('id', (int) $this->patient);
                abort_unless($data['selectedPatient'], 404);
                $visits = $data['history']->where('pet_id', (int) $this->patient)->values();
                $patientHistory = app(PatientHistory::class);
                $data['patientAge'] = $patientHistory->age($data['selectedPatient']);
                $data['patientVisitCount'] = $visits->count();
                $data['patientVaccinationCount'] = $visits->sum(fn ($visit) => $visit->medicalRecord->vaccinations->count());
                $events = $patientHistory->timeline($visits)->filter(fn ($event) => $search->matchesHistoryEvent($event['appointment'], $event['vaccination'], $needle))->values();
                $data['totalResults'] = $events->count();
                $data['pagination'] = new LengthAwarePaginator($events->forPage($this->getPage(), 12), $events->count(), 12, $this->getPage(), ['path' => request()->url()]);
                $data['patientTimeline'] = collect($data['pagination']->items());

                return view('livewire.clinic.workspace', $data);
            }
        }
        if ($key) {
            $rows = $data[$key]->filter(function ($row) use ($needle, $search, $matchingRecords, $filterVaccinationStatus): bool {
                if ($this->section === 'prescriptions' && ! $row->medicalRecord?->prescription) {
                    return false;
                }
                if ($this->section === 'vaccinations' && ! $row->medicalRecord?->vaccinations->isNotEmpty()) {
                    return false;
                }
                if ($filterVaccinationStatus && ! in_array($row->medicalRecord?->id, $matchingRecords)) {
                    return false;
                }
                if ($needle !== '' && ! $search->matches($this->section, $row, $needle)) {
                    return false;
                }
                if ($this->section === 'appointments') {
                    if ($this->status === 'scheduled' && $row->status === 'cancelled') {
                        return false;
                    }
                    if (in_array($this->status, ['pending', 'confirmed', 'completed', 'cancelled']) && $row->status !== $this->status) {
                        return false;
                    }
                    if ($this->date === 'today' && ! $row->appointment_date->isToday()) {
                        return false;
                    }
                    if ($this->date === 'upcoming' && (! $row->appointment_date->isFuture() || ! in_array($row->status, ['pending', 'confirmed']))) {
                        return false;
                    }
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) && $row->appointment_date->toDateString() !== $this->date) {
                        return false;
                    }
                }
                if ($this->section === 'inventory') {
                    if ($this->status === 'below_minimum' && (! $row->active || $row->quantity >= $row->reorder_level)) {
                        return false;
                    }
                    if ($this->status === 'low' && $row->quantity > $row->reorder_level) {
                        return false;
                    }
                    if ($this->status === 'expired' && ! $row->expires_at->lt(today())) {
                        return false;
                    }
                }

                return true;
            })->values();
            if ($this->section === 'appointments' && $this->date === 'upcoming') {
                $rows = $rows->sortBy('appointment_date')->values();
            }
            $data['totalResults'] = $rows->count();
            // Clinical vaccine reminders always use the complete scoped history.
            $data['pagination'] = new LengthAwarePaginator($rows->forPage($this->getPage(), 12), $rows->count(), 12, $this->getPage(), ['path' => request()->url()]);
            $data[$key] = new Collection($data['pagination']->items());
        }

        return view('livewire.clinic.workspace', $data);
    }
}
