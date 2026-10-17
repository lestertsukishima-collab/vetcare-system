@php
    $notifications = [
        ['count'=>$dashboardAlerts['pets_due_soon'], 'text'=>$dashboardAlerts['pets_due_soon'].' '.($dashboardAlerts['pets_due_soon'] === 1 ? 'pet has' : 'pets have').' vaccinations due within 30 days.', 'detail'=>'Today through '.today()->addDays(30)->format('F j, Y'), 'icon'=>'vaccinations', 'url'=>route('clinic.index',['section'=>'vaccinations','status'=>'due_soon'])],
    ];
    if ($dashboardAlerts['pets_overdue'] > 0) $notifications[] = ['count'=>$dashboardAlerts['pets_overdue'], 'text'=>$dashboardAlerts['pets_overdue'].' '.($dashboardAlerts['pets_overdue'] === 1 ? 'pet has' : 'pets have').' overdue vaccinations.', 'detail'=>'Review missed follow-up dates.', 'icon'=>'clock', 'url'=>route('clinic.index',['section'=>'vaccinations','status'=>'overdue'])];
    if ($role === 'admin') $notifications[] = ['count'=>$dashboardAlerts['medicines_below_minimum'], 'text'=>$dashboardAlerts['medicines_below_minimum'].' '.($dashboardAlerts['medicines_below_minimum'] === 1 ? 'medicine is' : 'medicines are').' below minimum stock.', 'detail'=>'Active medicines below their reorder threshold.', 'icon'=>'inventory', 'url'=>route('clinic.index',['section'=>'inventory','status'=>'below_minimum'])];
    $notifications[] = ['count'=>$dashboardAlerts['appointments_today'], 'text'=>$dashboardAlerts['appointments_today'].' '.($dashboardAlerts['appointments_today'] === 1 ? 'appointment' : 'appointments').' scheduled today.', 'detail'=>now()->format('F j, Y').' · '.config('app.timezone').' · excludes cancellations', 'icon'=>'appointments', 'url'=>route('clinic.index',['section'=>'appointments','date'=>'today','status'=>'scheduled'])];
@endphp
<section class="panel notifications-panel" aria-labelledby="notifications-heading">
    <div class="panel-heading"><div><h2 id="notifications-heading">{{ $role === 'owner' ? 'Your care reminders' : 'Clinic notifications' }}</h2><div class="panel-caption">A timely heads-up, connected to the next step.</div></div><span class="live-indicator"><span class="status-dot"></span>Updates automatically</span></div>
    <div class="notification-list" aria-live="polite" aria-atomic="true">
        @foreach($notifications as $notification)<a class="notification-item {{ $notification['count'] > 0 ? 'needs-attention' : 'all-clear' }}" href="{{ $notification['url'] }}" wire:navigate><span class="notification-icon"><x-clinic.icon :name="$notification['icon']" /></span><div><strong>{{ $notification['text'] }}</strong><span>{{ $notification['detail'] }}</span></div><x-clinic.icon name="arrow" /></a>@endforeach
    </div>
</section>
