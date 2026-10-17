@if(in_array($role,['admin','owner']) && ($role === 'admin' || $a->appointment_date->isFuture()))
<details class="editor">
    <summary>Reschedule / change veterinarian</summary>
    <livewire:clinic.action-form section="appointments" :record-id="$a->id" variant="reschedule" :key="'reschedule-'.$a->id" />
</details>
@endif
