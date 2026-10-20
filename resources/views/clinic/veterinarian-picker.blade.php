@php
    $eligibleVets = $vets->filter(fn ($v) => $v->user->active && $v->user->role === 'veterinarian');
    $pickerKey = ($variant ?? 'create').'-'.($recordId ?? 'new');
@endphp

<fieldset class="wide veterinarian-picker" aria-describedby="veterinarian-help-{{ $pickerKey }}">
    <legend>Choose a veterinarian</legend>
    <p id="veterinarian-help-{{ $pickerKey }}" class="helper">Select your doctor by photo, name, and specialization.</p>
    <div class="veterinarian-options">
        @forelse($eligibleVets as $vet)
            <label class="veterinarian-option" @if($reactive ?? false) wire:key="doctor-{{ $pickerKey }}-{{ $vet->id }}" @endif>
                <input type="radio" name="veterinarian_id" value="{{ $vet->id }}"
                    @if($reactive ?? false) wire:model="data.veterinarian_id" @endif
                    @checked(($formInput['veterinarian_id'] ?? null) == $vet->id)
                    aria-invalid="{{ $errors->has('veterinarian_id') ? 'true' : 'false' }}" required>
                <span class="veterinarian-option-body">
                    <x-account-avatar :user="$vet->user" :photo-url="$vet->bookingPhotoUrl()" class="veterinarian-avatar" />
                    <span class="veterinarian-option-info">
                        <strong>{{ $vet->user->name }}</strong>
                        <span>{{ $vet->specialization ?: 'General practice' }}</span>
                    </span>
                </span>
            </label>
        @empty
            <p class="helper" role="status">No veterinarians are currently available for booking. Please contact the clinic.</p>
        @endforelse
    </div>
    @error('veterinarian_id') <span class="field-error" role="alert">{{ $message }}</span> @enderror
</fieldset>
