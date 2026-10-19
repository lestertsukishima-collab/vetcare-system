<section class="panel history-browser" aria-labelledby="history-browser-heading">
    <div class="panel-heading">
        <div>
            <h2 id="history-browser-heading">Searchable pet medical history</h2>
            <p class="panel-caption">{{ $selectedPatient ? 'Find a diagnosis, treatment, medicine, vaccine, or date in this patient’s timeline.' : 'Search a pet, breed, owner, diagnosis, treatment, medicine, or vaccine. Open a patient to see their complete timeline.' }}</p>
        </div>
        @if($selectedPatient)<button type="button" class="small secondary btn btn-outline-secondary btn-sm" wire:click="clearPatient"><x-clinic.icon name="pets" />Choose another patient</button>@endif
    </div>
    <label class="sr-only" for="patient-history-search">Search patient history</label>
    <div class="history-search-row">
        <span class="history-search-icon"><x-clinic.icon name="search" /></span>
        <input class="form-control @error('search') is-invalid @enderror" id="patient-history-search" type="search" placeholder="{{ $selectedPatient ? 'Search this patient’s history…' : 'Find Bella, skin allergy, Anti-Rabies…' }}" maxlength="150" wire:model.live.debounce.300ms="search" autocomplete="off">
        @if($search)<button type="button" class="small secondary btn btn-outline-secondary btn-sm" wire:click="clearFilters">Clear search</button>@endif
    </div>
    <span class="loading-state" wire:loading.delay wire:target="search,patient,selectPatient,clearPatient"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span>Updating medical history…</span>
    @unless($selectedPatient)
        <div class="history-patient-list" aria-live="polite">
            @forelse($historyPatients->take(6) as $historyPet)
                <button type="button" class="history-patient-choice" wire:click="selectPatient('{{ $historyPet->id }}')" wire:loading.attr="disabled" wire:key="history-patient-{{ $historyPet->id }}" aria-label="Open {{ $historyPet->name }}'s medical history, patient {{ $historyPet->id }}">
                    <span class="patient-avatar"><x-clinic.icon name="pets" /></span>
                    <span><strong>{{ $historyPet->name }}</strong><span>{{ $historyPet->breed ?: $historyPet->species }} · {{ $historyPet->sex }}</span><small>{{ $historyPet->owner->user->name }} · Patient #{{ $historyPet->id }}</small></span>
                    <x-clinic.icon name="arrow" />
                </button>
            @empty
                <p class="empty wide">{{ $search ? 'No matching patients. Try another pet name or clinical detail.' : 'No patients available. Registered or assigned patients will appear here.' }}</p>
            @endforelse
        </div>
        @if($historyPatients->count() > 6)
            <label class="history-patient-picker">{{ $historyPatients->count() }} matching patients. Choose any patient:
                <select class="form-select @error('patient') is-invalid @enderror" wire:model.live="patient"><option value="">Select a patient</option>@foreach($historyPatients as $historyPet)<option value="{{ $historyPet->id }}">{{ $historyPet->name }} · {{ $historyPet->breed ?: $historyPet->species }} · {{ $historyPet->owner->user->name }} · #{{ $historyPet->id }}</option>@endforeach</select>
            </label>
        @endif
    @endunless
</section>
