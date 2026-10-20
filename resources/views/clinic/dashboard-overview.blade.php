@include('clinic.dashboard-alerts')
@include('clinic.dashboard-actions')
<div class="clinic-stats row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3">
    @foreach([['Registered pets',$pets->count(),'pets','Companions in your care'],['Upcoming visits',$upcoming->count(),'appointments','Pending & confirmed visits'],['Completed visits',$appointments->where('status','completed')->count(),'heart','Care delivered, all time'],['Vaccinations due',$due->count(),'vaccinations','Within 30 days or overdue']] as [$label,$count,$icon,$caption])
        <div class="col"><div class="stat"><div class="stat-top"><span class="muted">{{ $label }}</span><span class="stat-icon"><x-clinic.icon :name="$icon" /></span></div><strong>{{ $count }}</strong><small>{{ $caption }}</small></div></div>
    @endforeach
</div>
<div class="dashboard-grid"><div>
    <div class="panel"><div class="panel-heading"><div><h2>Your next 7 days</h2><div class="panel-caption">Open a day to see its appointments.</div></div><span class="muted">{{ today()->format('M') }}</span></div>
        <div class="week-strip">@foreach(range(0,6) as $offset)@php($day = today()->addDays($offset))@php($count = $appointments->filter(fn($a)=>$a->appointment_date->isSameDay($day) && $a->status !== 'cancelled')->count())<a class="week-day {{ $offset === 0 ? 'active' : '' }}" href="{{ route('clinic.index',['section'=>'appointments','date'=>$offset === 0 ? 'today' : $day->toDateString()]) }}" wire:navigate aria-label="{{ $day->format('l, F j') }}: {{ $count }} visits"><span>{{ $day->format('D') }}</span><strong>{{ $day->format('d') }}</strong><span class="day-count">{{ $count }} {{ $count === 1 ? 'visit' : 'visits' }}</span></a>@endforeach</div>
        <div class="panel-heading"><h2>Upcoming appointments</h2><a class="link" href="{{ route('clinic.index','appointments') }}" wire:navigate>View all →</a></div>@include('clinic.appointments',['visits'=>$upcoming->take(5),'compact'=>true])
    </div>
    <div class="panel"><div class="panel-heading"><div><h2>Care activity</h2><div class="panel-caption">Visits over the last 7 days · excludes cancellations</div></div><a class="link" href="{{ route('clinic.index','records') }}" wire:navigate>Medical history →</a></div>
        @php($days = collect(range(6,0))->map(fn($offset)=>today()->subDays($offset)))
        @php($counts = $days->map(fn($day)=>$appointments->filter(fn($a)=>$a->appointment_date->isSameDay($day) && $a->status !== 'cancelled')->count()))
        <div class="chart" role="img" aria-label="{{ $days->map(fn($day,$i)=>$day->format('D').': '.$counts[$i].' visits')->implode(', ') }}">@foreach($days as $i=>$day)<div class="chart-col"><span class="chart-value">{{ $counts[$i] }}</span><div class="chart-bar" style="height:{{ max(3, (int) ($counts[$i] / max(1,$counts->max()) * 70)) }}px"></div><small>{{ $day->format('D') }}</small></div>@endforeach</div>
    </div>
</div><div class="dashboard-side">
    <div class="panel"><div class="panel-heading"><div><h2>Vaccination follow-ups</h2><div class="panel-caption">Upcoming and overdue doses.</div></div><x-clinic.icon name="vaccinations" /></div>
        @forelse($due->sortBy(fn($row)=>$row['vaccination']->next_due_date)->take(4) as $row)<div class="followup"><span class="followup-icon"><x-clinic.icon name="pets" /></span><div><strong>{{ $row['appointment']->pet->name }}</strong><p>{{ $row['vaccination']->vaccine_name }}</p><p>Last vaccination: {{ $row['vaccination']->date_given->format('F j, Y') }}</p><p>Next vaccination: {{ $row['vaccination']->next_due_date->format('F j, Y') }}</p><x-clinic.vaccination-status :status="$row['status']" /></div></div>@empty<div class="empty"><span class="empty-art"><x-clinic.icon name="check" /></span><strong>All caught up.</strong>No vaccinations due in the next 30 days.</div>@endforelse
        <a class="quick-link" href="{{ route('clinic.index','vaccinations') }}" wire:navigate><x-clinic.icon name="vaccinations" />Vaccination schedule<x-clinic.icon name="arrow" /></a>
    </div>
    @if($role === 'admin')<div class="panel"><div class="panel-heading"><h2>Inventory watch</h2><x-clinic.icon name="inventory" /></div><p class="helper">{{ $dashboardAlerts['medicines_below_minimum'] }} active medicines below minimum stock.</p><a class="quick-link" href="{{ route('clinic.index',['section'=>'inventory','status'=>'below_minimum']) }}" wire:navigate><x-clinic.icon name="inventory" />Review low stock<x-clinic.icon name="arrow" /></a></div>@endif
</div></div>
