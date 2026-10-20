<x-clinic.form class="fields" :action="route('clinic.update', [$section, $recordId])" method="PATCH" :form-key="($variant === 'reschedule' ? 'reschedule-' : 'update-').$section.'-'.$recordId" :reactive="true" :values="$data">
    @if($section === 'pets')
        @include('clinic.pet-fields', ['pet' => null])
    @elseif($section === 'appointments')
        @include('clinic.veterinarian-picker', ['formInput' => $data])
        <x-clinic.field name="appointment_date" label="New visit date & time" type="datetime-local" :min="now()->format('Y-m-d\TH:i')" required />
        <p class="wide helper">Changing the visit returns it to pending confirmation. The booked service fee stays the same.</p>
    @elseif($section === 'users')
        <x-clinic.field name="name" label="Full name" required />
        <x-clinic.field name="email" label="Email" type="email" required />
        <x-clinic.select name="role" label="Role" :options="['owner'=>'Pet Owner','veterinarian'=>'Veterinarian','admin'=>'Admin']" required />
        <x-clinic.select name="active" label="Account status" :options="[1=>'Active',0=>'Inactive']" required />
        @if(($data['role'] ?? '') === 'owner')<x-clinic.field name="phone" label="Phone" /><x-clinic.field name="address" label="Address" type="textarea" />@endif
        @if(($data['role'] ?? '') === 'veterinarian')<x-clinic.field name="license_number" label="License number" /><x-clinic.field name="specialization" label="Specialization" />@endif
    @elseif($section === 'services')
        <x-clinic.field name="name" label="Service name" required /><x-clinic.field name="price" label="Fee (PHP)" type="number" min="0" step="0.01" required />
        <x-clinic.field name="description" label="Description" type="textarea" /><x-clinic.select name="active" label="Status" :options="[1=>'Active',0=>'Inactive']" required />
    @elseif($section === 'inventory' && $variant === 'stock')
        <x-clinic.field name="quantity_change" label="Quantity change (+ receive / − remove)" type="number" step="1" required />
        <x-clinic.field name="notes" label="Reason / delivery reference" required maxlength="1000" />
    @elseif($section === 'inventory')
        <x-clinic.field name="name" label="Medicine name" required /><x-clinic.field name="unit" label="Unit" required />
        <x-clinic.field name="unit_price" label="Unit price (PHP)" type="number" step="0.01" min="0" required />
        <x-clinic.field name="reorder_level" label="Reorder level" type="number" min="0" required />
        <x-clinic.field name="expires_at" label="Expiry date" type="date" required />
        <x-clinic.select name="active" label="Status" :options="[1=>'Active',0=>'Inactive']" required />
    @endif
    <div class="wide form-actions"><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $variant === 'stock' ? 'Post stock change' : 'Save changes' }}</button><span class="helper">Changes are saved securely to your clinic.</span></div>
</x-clinic.form>
