<section class="panel vaccination-reminders" aria-labelledby="vaccination-reminders-heading">
    <div class="panel-heading">
        <div>
            <h2 id="vaccination-reminders-heading">Current vaccination follow-ups</h2>
            <p class="helper">The latest dose of each vaccine determines its reminder. Dates follow the clinic's timezone.</p>
        </div>
        <label class="reminder-filter">
            <span>Reminder status</span>
            <select wire:model.live="status">
                <option value="">All reminders</option>
                <option value="due_soon">Due within 30 days</option>
                <option value="due_today">Due today</option>
                <option value="overdue">Overdue</option>
                <option value="upcoming">Upcoming (after 30 days)</option>
                <option value="unscheduled">No follow-up set</option>
                <option value="attention">Overdue or due within 30 days</option>
            </select>
        </label>
    </div>
    <div class="vaccination-reminder-grid" aria-live="polite">
        @forelse($vaccinationFollowUps as $row)
            @php($v = $row['vaccination'])
            <article class="vaccination-card {{ in_array($row['status'], ['overdue', 'due_today', 'due_soon']) ? 'needs-attention' : '' }}" wire:key="vaccination-reminder-{{ $v->id }}">
                <div class="vaccination-card-heading">
                    <span class="notification-icon"><x-clinic.icon name="vaccinations" /></span>
                    <x-clinic.vaccination-status :status="$row['status']" />
                </div>
                <h3>{{ $row['appointment']->pet->name }}’s {{ $v->vaccine_name }} vaccination</h3>
                <dl>
                    <div><dt>Last vaccination</dt><dd>{{ $v->date_given->format('F j, Y') }}</dd></div>
                    <div><dt>Next vaccination</dt><dd>{{ $v->next_due_date?->format('F j, Y') ?? 'Not scheduled' }}</dd></div>
                </dl>
                <p class="helper">Recorded by {{ $row['appointment']->veterinarian->user->name }}</p>
            </article>
        @empty
            <div class="empty reminder-empty">
                @if($status !== '' || $search !== '')
                    <p>No vaccination reminders match these filters.</p>
                    <button type="button" class="button secondary" wire:click="clearFilters">Clear filters</button>
                @else
                    <p>Vaccination reminders will appear after a dose is recorded.</p>
                @endif
            </div>
        @endforelse
    </div>
</section>
