@php
    $shortcuts = match ($role) {
        'owner' => [
            ['pets', 'Add a pet', 'Create a profile before booking their first visit.', route('clinic.index', 'pets').'#create'],
            ['appointments', 'Book a visit', 'Choose a veterinarian and request an appointment.', route('clinic.index', 'appointments').'#create'],
            ['records', 'Medical history', 'Review your pets\' diagnoses and past care.', route('clinic.index', 'records')],
        ],
        'veterinarian' => [
            ['appointments', 'Today\'s visits', 'Review your appointments scheduled for today.', route('clinic.index', ['section' => 'appointments', 'date' => 'today', 'status' => 'scheduled'])],
            ['records', 'Record an examination', 'Complete the findings for a confirmed visit.', route('clinic.index', 'records').'#create'],
            ['vaccinations', 'Vaccination follow-ups', 'Review vaccines due within 30 days.', route('clinic.index', ['section' => 'vaccinations', 'status' => 'due_soon'])],
        ],
        default => [
            ['appointments', 'Today\'s appointments', 'Review requests and manage the daily schedule.', route('clinic.index', ['section' => 'appointments', 'date' => 'today', 'status' => 'scheduled'])],
            ['owners', 'Register an owner', 'Create an account with the owner\'s contact details.', route('clinic.index', 'owners').'#create'],
            ['payments', 'Record a payment', 'Apply a received payment to an outstanding invoice.', route('clinic.index', 'payments').'#create'],
        ],
    };
@endphp
<section class="dashboard-actions" aria-labelledby="dashboard-actions-heading">
    <h2 id="dashboard-actions-heading">Quick actions</h2>
    <div class="row row-cols-1 row-cols-md-3 g-3">
        @foreach($shortcuts as [$icon, $title, $description, $url])
            <div class="col"><a class="task-card" href="{{ $url }}" wire:navigate><span class="task-icon"><x-clinic.icon :name="$icon" /></span><div><strong>{{ $title }}</strong><p>{{ $description }}</p></div><x-clinic.icon name="arrow" /></a></div>
        @endforeach
    </div>
</section>
