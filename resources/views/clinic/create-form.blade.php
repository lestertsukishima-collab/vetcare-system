@php
    $formTitles = ['pets'=>'Register a pet','appointments'=>'Book an appointment','records'=>'Record an examination','owners'=>'Create a pet owner account','veterinarians'=>'Add a veterinarian','users'=>'Create a user','services'=>'Add a service','inventory'=>'Add medicine','invoices'=>'Invoice a completed visit','payments'=>'Record a payment','settings'=>'Clinic details'];
    $canCreate = ($role === 'admin' && in_array($section,['pets','appointments','owners','veterinarians','users','services','inventory','invoices','payments','settings'])) || ($role === 'owner' && in_array($section,['pets','appointments'])) || ($role === 'veterinarian' && $section === 'records');
    $formInput = $reactive ?? false ? $data : (old('_form_key') === 'create-'.$section ? session()->getOldInput() : []);
    foreach (['items','vaccinations'] as $rowGroup) {
        if (array_key_exists($rowGroup,$formInput)) {
            $rows = is_array($formInput[$rowGroup]) ? $formInput[$rowGroup] : [];
            $rows = array_filter($rows, fn($row,$key)=>is_array($row) && is_int($key) && $key >= 0 && $key <= 1000000, ARRAY_FILTER_USE_BOTH);
            $formInput[$rowGroup] = array_map(fn($row)=>array_map(fn($value)=>is_scalar($value) || is_null($value) ? $value : '',$row),$rows);
            if (!$formInput[$rowGroup]) unset($formInput[$rowGroup]);
        }
    }
@endphp
@if($canCreate)<details id="create" class="panel create" open><summary>{{ $formTitles[$section] }}</summary><x-clinic.form :action="route('clinic.store',$section)" :form-key="'create-'.$section" :reactive="$reactive ?? false" :values="$data ?? []" class="fields">
@if($section === 'pets')
@include('clinic.pet-fields',['pet'=>null])
@if($role === 'admin')<label>Pet owner<select class="form-select @error('owner_id') is-invalid @enderror" name="owner_id" @if($reactive ?? false) wire:model="data.owner_id" @endif required><option value="">Choose owner</option>@foreach($owners->filter(fn($o)=>$o->user->active) as $o)<option value="{{ $o->id }}" @selected(($formInput['owner_id'] ?? null) == $o->id)>{{ $o->user->name }} · {{ $o->user->email }}</option>@endforeach</select></label>@endif
@elseif($section === 'appointments')
<label>Patient<select class="form-select @error('pet_id') is-invalid @enderror" name="pet_id" @if($reactive ?? false) wire:model="data.pet_id" @endif required><option value="">Choose pet</option>@foreach($pets as $p)<option value="{{ $p->id }}" @selected(($formInput['pet_id'] ?? null) == $p->id)>{{ $p->name }} · {{ $p->owner->user->name }}</option>@endforeach</select></label>
<label>Veterinarian<select class="form-select @error('veterinarian_id') is-invalid @enderror" name="veterinarian_id" @if($reactive ?? false) wire:model="data.veterinarian_id" @endif required><option value="">Choose veterinarian</option>@foreach($vets->filter(fn($v)=>$v->user->active && $v->user->role === 'veterinarian') as $v)<option value="{{ $v->id }}" @selected(($formInput['veterinarian_id'] ?? null) == $v->id)>{{ $v->user->name }} · {{ $v->specialization ?: 'General practice' }}</option>@endforeach</select></label>
<label>Service<select class="form-select @error('service_id') is-invalid @enderror" name="service_id" @if($reactive ?? false) wire:model.live="data.service_id" @endif required><option value="">Choose service</option>@foreach($services->where('active',true) as $s)<option value="{{ $s->id }}" @selected(($formInput['service_id'] ?? null) == $s->id)>{{ $s->name }} · ₱{{ number_format($s->price,2) }}</option>@endforeach</select></label>
<x-clinic.field name="appointment_date" label="Visit date & time" type="datetime-local" :min="now()->format('Y-m-d\TH:i')" required />
<x-clinic.field name="reason" label="Reason for visit" type="textarea" required maxlength="5000" />
@if(($reactive ?? false) && !empty($data['service_id']) && ($selectedService = $services->firstWhere('id',$data['service_id'])))<div class="wide booking-summary"><span class="stat-icon"><x-clinic.icon name="services" /></span><div><strong>{{ $selectedService->name }}</strong><span>30-minute reserved visit · Fee confirmed when booked</span></div><strong>₱{{ number_format($selectedService->price,2) }}</strong></div>@endif
<div class="wide helper">Visits reserve a 30-minute slot in {{ config('app.timezone') }}. Your request will be pending until confirmed by the clinic.</div>
@elseif($section === 'records')
<label class="wide">Confirmed appointment<select class="form-select @error('appointment_id') is-invalid @enderror" name="appointment_id" @if($reactive ?? false) wire:model="data.appointment_id" @endif required><option value="">Choose appointment</option>@foreach($appointments->where('status','confirmed') as $a)<option value="{{ $a->id }}" @selected(($formInput['appointment_id'] ?? request('appointment')) == $a->id)>{{ $a->pet->name }} · {{ $a->appointment_date->format('M d, Y h:i A') }}</option>@endforeach</select></label>
@foreach(['symptoms'=>'Symptoms','diagnosis'=>'Diagnosis','treatment'=>'Treatment plan'] as $field=>$label)<x-clinic.field :name="$field" :label="$label" type="textarea" required maxlength="10000" />@endforeach
<div class="wide"><x-clinic.field name="notes" label="Clinical notes" type="textarea" maxlength="10000" /></div>
@include('clinic.prescription-fields')
@include('clinic.vaccination-fields')
<div class="wide helper">Saving completes this visit and generates an invoice. Clinical records are retained as entered.</div>
@elseif(in_array($section,['users','owners','veterinarians']))
<x-clinic.field name="name" label="Full name" required maxlength="100" /><x-clinic.field name="email" label="Email address" type="email" required maxlength="255" /><x-clinic.field name="password" label="Initial password (12+ characters)" type="password" required minlength="12" autocomplete="new-password" />
@if($section === 'users')<x-clinic.select name="role" label="Role" :options="['owner'=>'Pet Owner','veterinarian'=>'Veterinarian','admin'=>'Admin']" value="owner" required />@else<input type="hidden" name="role" @if($reactive ?? false) wire:model="data.role" @endif value="{{ $section === 'owners' ? 'owner' : 'veterinarian' }}">@endif
@if($section !== 'veterinarians')<x-clinic.field name="phone" label="Phone (owners)" maxlength="50" /><x-clinic.field name="address" label="Address (owners)" type="textarea" maxlength="1000" />@endif
@if($section !== 'owners')<x-clinic.field name="license_number" label="License number (veterinarians)" maxlength="100" /><x-clinic.field name="specialization" label="Specialization (veterinarians)" maxlength="100" />@endif
@elseif($section === 'services')
<x-clinic.field name="name" label="Service name" required maxlength="100" /><x-clinic.field name="price" label="Fee (PHP)" type="number" min="0" max="99999999.99" step="0.01" required /><x-clinic.field name="description" label="Description" type="textarea" maxlength="5000" />
@elseif($section === 'inventory')
<x-clinic.field name="name" label="Medicine name / batch" required maxlength="100" /><x-clinic.field name="unit" label="Unit (tablet, bottle, vial…)" value="unit" required maxlength="40" /><x-clinic.field name="unit_price" label="Selling price per unit (PHP)" type="number" value="0" min="0" max="10000" step="0.01" required /><x-clinic.field name="quantity" label="Opening quantity" type="number" value="0" min="0" max="1000000" required /><x-clinic.field name="reorder_level" label="Reorder threshold" type="number" value="10" min="0" max="1000000" required /><x-clinic.field name="expires_at" label="Expiry date" type="date" required />
@elseif($section === 'invoices')
<label class="wide">Completed visit without invoice<select class="form-select @error('appointment_id') is-invalid @enderror" name="appointment_id" @if($reactive ?? false) wire:model="data.appointment_id" @endif required><option value="">Choose completed visit</option>@foreach($appointments->where('status','completed')->filter(fn($a)=>!$a->invoice) as $a)<option value="{{ $a->id }}">{{ $a->pet->name }} · {{ $a->appointment_date->format('M d, Y') }}</option>@endforeach</select></label>
@elseif($section === 'payments')
@php($paymentRequestKey = $formInput['request_key'] ?? null)
@php($paymentRequestKey = is_string($paymentRequestKey) && \Illuminate\Support\Str::isUuid($paymentRequestKey) ? $paymentRequestKey : (string) \Illuminate\Support\Str::uuid())
<input type="hidden" name="request_key" @if($reactive ?? false) wire:model="data.request_key" @endif value="{{ $paymentRequestKey }}">
<label>Invoice<select class="form-select @error('invoice_id') is-invalid @enderror" name="invoice_id" @if($reactive ?? false) wire:model="data.invoice_id" @endif required><option value="">Choose invoice</option>@foreach($invoices->filter(fn($i)=>$i->balanceCents()>0) as $i)<option value="{{ $i->id }}" @selected(($formInput['invoice_id'] ?? request('invoice')) == $i->id)>#{{ $i->id }} · {{ $i->appointment->pet->name }} · ₱{{ number_format($i->balanceCents()/100,2) }} due</option>@endforeach</select></label><x-clinic.field name="amount" label="Amount received (PHP)" type="number" min="0.01" max="99999999.99" step="0.01" required /><x-clinic.select name="method" label="Payment method" :options="['cash'=>'Cash','bank_transfer'=>'Bank transfer','card'=>'Card']" value="cash" required /><x-clinic.field name="reference" label="Transaction reference (bank / card)" maxlength="255" /><x-clinic.field name="paid_at" label="Date & time received" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" :max="now()->format('Y-m-d\TH:i')" required />
@elseif($section === 'settings')
<x-clinic.field name="name" label="Clinic name" :value="$clinic->name ?? 'VetCare Animal Clinic'" required maxlength="100" /><x-clinic.field name="phone" label="Phone" :value="$clinic->phone ?? ''" maxlength="50" /><x-clinic.field name="address" label="Address" type="textarea" :value="$clinic->address ?? ''" maxlength="1000" />
@endif
<div class="wide form-actions"><button class="btn btn-primary" @if($reactive ?? false) wire:loading.attr="disabled" wire:target="save" @endif>{{ match($section) {'appointments'=>'Request appointment','records'=>'Complete visit & save record','payments'=>'Record received payment',default=>'Save details'} }}</button></div></x-clinic.form></details>@endif
