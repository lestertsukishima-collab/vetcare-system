@php
    $role = auth()->user()->role;
    $navigation = app(\App\Support\ClinicNavigation::class);
    $links = $navigation->links($role);
@endphp
