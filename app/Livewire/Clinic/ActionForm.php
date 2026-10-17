<?php

namespace App\Livewire\Clinic;

use App\Concerns\ClinicalRowValidationRules;
use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\Pet;
use App\Models\Service;
use App\Models\User;
use App\Services\ClinicAccess;
use App\Services\ClinicCommands;
use App\Services\WorkspaceData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ActionForm extends Component
{
    use ClinicalRowValidationRules;

    #[Locked]
    public string $section;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public string $variant = 'create';

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $saved = false;

    public function mount(string $section, ?int $recordId = null, string $variant = 'create'): void
    {
        $this->section = $section;
        $this->recordId = $recordId;
        $this->variant = $variant;
        $user = $this->actor();
        app(ClinicAccess::class)->authorize($user, $section);
        abort_unless(in_array($variant, ['create', 'edit', 'stock', 'reschedule']), 404);
        $this->data = $this->defaults();
        if ($recordId) {
            $model = match ($section) {
                'pets' => Pet::findOrFail($recordId), 'users' => User::with(['owner', 'veterinarian'])->findOrFail($recordId),
                'services' => Service::findOrFail($recordId), 'inventory' => Medicine::findOrFail($recordId),
                'appointments' => Appointment::visibleTo($user)->findOrFail($recordId), default => abort(404),
            };
            if ($section === 'pets') {
                abort_unless($user->role === 'admin' || ($user->role === 'owner' && $model->owner->user_id === $user->id), 403);
            }
            $this->data = array_replace($this->data, $model->only(['name', 'species', 'breed', 'sex', 'weight', 'color', 'notes', 'email', 'role', 'active', 'price', 'description', 'unit', 'unit_price', 'reorder_level', 'veterinarian_id']));
            if ($model instanceof User || $model instanceof Medicine || $model instanceof Service) {
                $this->data['active'] = $model->getAttribute('active') ? '1' : '0';
            }
            if ($model instanceof Pet) {
                $this->data['birth_date'] = $model->birth_date?->format('Y-m-d');
            }
            if ($model instanceof Medicine) {
                $this->data['expires_at'] = $model->expires_at->format('Y-m-d');
            }
            if ($model instanceof Appointment) {
                $this->data['operation'] = 'reschedule';
                $this->data['appointment_date'] = $model->appointment_date->format('Y-m-d\TH:i');
            }
            if ($model instanceof User) {
                $this->data += ['phone' => $model->owner?->phone, 'address' => $model->owner?->address, 'license_number' => $model->veterinarian?->license_number, 'specialization' => $model->veterinarian?->specialization];
            }
        }
        if ($variant === 'stock') {
            $this->data = ['operation' => 'stock', 'quantity_change' => '', 'notes' => ''];
        }
        $formKey = $variant === 'create' ? 'create-'.$section : ($variant === 'reschedule' ? 'reschedule-' : 'update-').$section.'-'.$recordId;
        if (old('_form_key') === $formKey) {
            $old = session()->getOldInput();
            unset($old['_token'], $old['_method'], $old['_form_key'], $old['password']);
            $this->data = array_replace($this->data, $old);
        }
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return match ($this->section) {
            'pets' => ['sex' => 'Unknown'],
            'records' => ['appointment_id' => request('appointment', ''), 'items' => [$this->emptyRow('items')], 'vaccinations' => [$this->emptyRow('vaccinations')]],
            'payments' => ['invoice_id' => request('invoice', ''), 'method' => 'cash', 'paid_at' => now()->format('Y-m-d\TH:i'), 'request_key' => (string) Str::uuid()],
            'inventory' => ['unit' => 'unit', 'unit_price' => 0, 'quantity' => 0, 'reorder_level' => 10],
            'owners' => ['role' => 'owner'], 'veterinarians' => ['role' => 'veterinarian'], 'users' => ['role' => 'owner'],
            'settings' => (array) DB::table('clinic_settings')->first(), default => [],
        };
    }

    /** @return array<string, mixed> */
    private function emptyRow(string $group): array
    {
        return $group === 'items' ? ['medicine_id' => '', 'quantity' => '', 'dosage' => '', 'dispense_now' => '1'] : ['vaccine_name' => '', 'date_given' => '', 'next_due_date' => '', 'batch_number' => ''];
    }

    public function addRow(string $group): void
    {
        abort_unless($this->section === 'records' && in_array($group, ['items', 'vaccinations']), 403);
        $rows = $this->rowData($group);
        if (count($rows) < 20) {
            $next = 0;
            foreach (array_keys($rows) as $index) {
                if (is_int($index) && $index >= $next) {
                    $next = $index + 1;
                }
            }
            $this->data[$group][$next] = $this->emptyRow($group);
        }
    }

    public function removeRow(string $group, int $index): void
    {
        abort_unless($this->section === 'records' && in_array($group, ['items', 'vaccinations']), 403);
        $rows = $this->rowData($group);
        unset($rows[$index]);
        $this->data[$group] = $rows ?: [$this->emptyRow($group)];
        $this->resetValidation();
    }

    /** @return array<array-key, mixed> */
    private function rowData(string $group): array
    {
        $user = $this->actor();
        app(ClinicAccess::class)->authorize($user, $this->section);
        abort_unless($user->role === 'veterinarian', 403);

        return validator([$group => $this->data[$group] ?? []], [$group => ['array', 'max:20', $this->clinicalRowKeys()], $group.'.*' => 'array'])->validate()[$group];
    }

    public function save(): void
    {
        $this->saved = false;
        // Match Laravel's standard HTTP trimming and empty-string normalization.
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                return array_map($normalize, $value);
            }
            if (is_string($value)) {
                return trim($value) === '' ? null : trim($value);
            }

            return $value;
        };
        $input = $normalize($this->data);
        if (array_key_exists('password', $this->data)) {
            $input['password'] = $this->data['password'];
        }
        $commands = app(ClinicCommands::class);
        if ($this->recordId) {
            $redirect = $commands->update($this->actor(), $this->section, $this->recordId, $input);
            if ($redirect) {
                session()->flash('success', 'Account updated. Your workspace now follows your new role.');
                $this->redirect($redirect, navigate: true);

                return;
            }
        } else {
            $commands->store($this->actor(), $this->section, $input);
            if ($this->section !== 'settings') {
                $this->data = $this->defaults();
            }
        }
        $this->resetValidation();
        $this->saved = true;
        $this->dispatch('clinic-saved');
        $this->dispatch('clinic-toast', message: 'Saved successfully.');
    }

    private function actor(): User
    {
        return User::findOrFail(auth()->id());
    }

    public function render(): View
    {
        // Every update rechecks the current account, including role changes in another session.
        $user = $this->actor();
        app(ClinicAccess::class)->authorize($user, $this->section);
        $context = app(WorkspaceData::class)->formFor($user, $this->section);

        return view('livewire.clinic.action-form', $context + ['role' => $user->role, 'reactive' => true, 'pet' => null]);
    }
}
