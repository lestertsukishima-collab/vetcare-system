@props(['status'])
@php
    $labels = ['overdue'=>'Overdue', 'due_today'=>'Due today', 'due_soon'=>'Due within 30 days', 'upcoming'=>'Upcoming', 'unscheduled'=>'No follow-up set'];
    $colors = ['overdue'=>'overdue', 'due_today'=>'pending', 'due_soon'=>'pending', 'upcoming'=>'confirmed', 'unscheduled'=>''];
@endphp
<span class="badge {{ $colors[$status] ?? '' }}">{{ $labels[$status] ?? $status }}</span>
