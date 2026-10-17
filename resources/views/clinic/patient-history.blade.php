<section class="panel history-profile" aria-labelledby="history-patient-name" wire:key="patient-profile-{{ $selectedPatient->id }}">
    <div class="history-profile-heading">
        <span class="patient-avatar"><x-clinic.icon name="pets" /></span>
        <div>
            <div class="eyebrow">Patient #{{ $selectedPatient->id }} · {{ $selectedPatient->species }}</div>
            <h2 id="history-patient-name">{{ $selectedPatient->name }}</h2>
            <p>{{ $selectedPatient->breed ?: 'Breed unspecified' }} <span aria-hidden="true">|</span> {{ $selectedPatient->sex }} <span aria-hidden="true">|</span> {{ $patientAge }}</p>
        </div>
        <div class="history-profile-counts"><span><strong>{{ $patientVisitCount }}</strong>{{ $patientVisitCount === 1 ? 'clinical visit' : 'clinical visits' }}</span><span><strong>{{ $patientVaccinationCount }}</strong>{{ $patientVaccinationCount === 1 ? 'vaccine dose' : 'vaccine doses' }}</span></div>
    </div>
    <dl class="history-profile-details">
        <div><dt>Owner</dt><dd>{{ $selectedPatient->owner->user->name }}</dd></div>
        <div><dt>Birth date</dt><dd>{{ $selectedPatient->birth_date?->format('M d, Y') ?? 'Not recorded' }}</dd></div>
        <div><dt>Weight</dt><dd>{{ $selectedPatient->weight !== null ? $selectedPatient->weight.' kg' : 'Not recorded' }}</dd></div>
        <div><dt>Color</dt><dd>{{ $selectedPatient->color ?: 'Not recorded' }}</dd></div>
    </dl>
    @if($selectedPatient->notes)<div class="history-patient-notes"><strong>Patient notes</strong><p>{{ $selectedPatient->notes }}</p></div>@endif
</section>
<section class="panel history-timeline-panel" aria-labelledby="medical-history-heading">
    <div class="panel-heading"><div><h2 id="medical-history-heading">Medical history</h2><p class="panel-caption">Newest first · Visits use the appointment date; vaccines use the administration date.</p></div><span class="muted" role="status">{{ $totalResults }} {{ $totalResults === 1 ? 'event' : 'events' }}{{ $search ? ' matching your search' : '' }}</span></div>
    <ol class="medical-timeline" aria-live="polite">
        @forelse($patientTimeline as $event)
            @php($appointment = $event['appointment'])
            @php($record = $appointment->medicalRecord)
            <li class="medical-timeline-event" wire:key="patient-event-{{ $event['key'] }}">
                <span class="medical-timeline-marker"><x-clinic.icon :name="$event['type'] === 'vaccination' ? 'vaccinations' : 'records'" /></span>
                <article class="medical-timeline-card">
                    <div class="medical-timeline-heading"><time datetime="{{ $event['date']->toDateString() }}">{{ $event['date']->format('M d, Y') }}</time><span class="badge">{{ $event['type'] === 'vaccination' ? 'Vaccination' : 'Clinical visit' }}</span></div>
                    @if($event['vaccination'])
                        @php($vaccination = $event['vaccination'])
                        <h3>Vaccination: {{ $vaccination->vaccine_name }}</h3>
                        <dl class="history-vaccine-details"><div><dt>Next due</dt><dd>{{ $vaccination->next_due_date?->format('M d, Y') ?? 'Not scheduled' }}</dd></div><div><dt>Batch</dt><dd>{{ $vaccination->batch_number ?: 'Not recorded' }}</dd></div></dl>
                        @if(in_array($vaccination->id, $latestVaccinationIds))<x-clinic.vaccination-status :status="app(\App\Services\VaccinationSchedule::class)->status($vaccination)" />@else<span class="badge">Historical dose</span>@endif
                    @else
                        <h3>{{ $appointment->service_name ?? $appointment->service->name }}</h3>
                        <dl class="history-clinical-details">
                            @foreach(['diagnosis'=>'Diagnosis', 'treatment'=>'Treatment', 'symptoms'=>'Symptoms', 'notes'=>'Clinical notes'] as $field=>$label)
                                <div><dt>{{ $label }}</dt><dd>{{ $record->$field ?: 'None recorded' }}</dd></div>
                            @endforeach
                        </dl>
                        @if($record->prescription) @include('clinic.history-prescription') @endif
                    @endif
                    <p class="medical-timeline-footer">{{ $appointment->veterinarian->user->name }} · Record #{{ $record->id }}</p>
                </article>
            </li>
        @empty
            <li class="empty"><span class="empty-art"><x-clinic.icon name="records" /></span><strong>{{ $search ? 'No matching history.' : 'No medical history yet.' }}</strong>{{ $search ? 'Try a diagnosis, treatment, medicine, vaccine, or date.' : 'Completed examinations and recorded vaccinations will appear here.' }}@if($search)<br><button type="button" class="small secondary" wire:click="clearFilters">Clear search</button>@endif</li>
        @endforelse
    </ol>
</section>
