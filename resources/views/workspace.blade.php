<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#244e43">
    <title>{{ ucfirst($section) }} · VetCare</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('clinic.css') }}?v={{ filemtime(public_path('clinic.css')) }}" data-navigate-track>
    <link rel="stylesheet" href="{{ asset('clinic-responsive.css') }}?v={{ filemtime(public_path('clinic-responsive.css')) }}" data-navigate-track>
    @livewireStyles
    <script data-navigate-once>try{let a=localStorage.getItem('flux.appearance')||localStorage.getItem('vetcare-theme')||'light';document.documentElement.dataset.theme=a==='system'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):a}catch{}</script>
    <script src="{{ asset('clinic.js') }}?v={{ filemtime(public_path('clinic.js')) }}" defer data-navigate-once></script>
</head>
<body>
@php
    $role = auth()->user()->role;
    $links = ['dashboard'=>'Overview','pets'=>$role === 'owner' ? 'My pets' : 'Patients','appointments'=>'Appointments','records'=>'Medical records','prescriptions'=>'Prescriptions','vaccinations'=>'Vaccinations'];
    if (in_array($role,['owner','admin'])) $links['invoices'] = 'Invoices';
    if ($role === 'admin') $links += ['owners'=>'Pet owners','veterinarians'=>'Veterinarians','services'=>'Services','inventory'=>'Medicine inventory','transactions'=>'Stock history','payments'=>'Payments','users'=>'Users','reports'=>'Reports','settings'=>'Clinic settings'];
@endphp

<a href="#main-content" class="skip-link">Skip to content</a>
<button type="button" class="nav-backdrop" aria-label="Close navigation" data-nav-close></button>
<aside class="sidebar" id="clinic-navigation" aria-label="Main navigation">
    <div class="mobile-nav-heading"><span>Navigation</span><button type="button" class="icon-button" data-nav-close aria-label="Close navigation"><x-clinic.icon name="close" /></button></div>
    <a class="brand" href="{{ route('dashboard') }}" wire:navigate><span class="brand-mark"><x-clinic.icon name="pets" /></span><span>VetCare<span class="brand-period">.</span><small>THE CARE WORKSPACE</small></span></a>
    <div class="workspace-label"><span class="status-dot"></span>{{ $role === 'owner' ? 'Pet owner' : ucfirst($role) }} workspace</div>
    <nav class="main-nav">
        <div class="nav-group-label">Care management</div>
        @foreach($links as $key => $label)
            @if($key === 'owners')<div class="nav-group-label">Practice management</div>@endif
            <a class="{{ $section === $key ? 'active' : '' }}" href="{{ $key === 'dashboard' ? route('dashboard') : route('clinic.index',$key) }}" @if($section === $key) aria-current="page" @endif wire:navigate><x-clinic.icon :name="$key" /><span>{{ $label }}</span>@if($section === $key)<span class="active-indicator"></span>@endif</a>
        @endforeach
    </nav>
    <div class="sidebar-note"><span class="note-icon"><x-clinic.icon name="heart" /></span><strong>A little care.<br>A lifetime of difference.</strong><span>Every companion matters.</span></div>
    <a class="sidebar-account" href="{{ route('profile.edit') }}" wire:navigate><x-clinic.icon name="settings" /> Account settings <x-clinic.icon name="arrow" /></a>
</aside>
<main class="workspace" id="main-content">
    <header class="topbar">
        <div class="topbar-left"><button type="button" class="icon-button mobile-menu" aria-label="Open navigation" aria-expanded="false" aria-controls="clinic-navigation" data-nav-toggle><x-clinic.icon name="menu" /></button><div class="breadcrumb"><span>Workspace</span><span class="breadcrumb-slash">/</span><strong>{{ $links[$section] }}</strong></div></div>
        <div class="topbar-actions">
            <button class="command-trigger" type="button" aria-label="Open quick navigation" data-command-open><x-clinic.icon name="search" /><span>Quick navigation</span><kbd>Ctrl K</kbd></button>
            <button type="button" class="icon-button" data-theme-toggle aria-label="Toggle color theme"><x-clinic.icon name="sun" /></button>
            <details class="user-menu"><summary aria-label="Account menu"><span class="avatar">{{ auth()->user()->initials() }}</span><span class="profile-name"><strong>{{ auth()->user()->name }}</strong><span>{{ $role === 'owner' ? 'Pet Owner' : ucfirst($role) }}</span></span><span class="chevron">⌄</span></summary><div class="user-dropdown"><a href="{{ route('profile.edit') }}" wire:navigate><x-clinic.icon name="settings" />Account settings</a><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-clinic.icon name="logout" />Sign out</button></form></div></details>
        </div>
    </header>
    <livewire:clinic.workspace :section="$section" />
    <footer><span><span class="footer-dot"></span> VetCare · Thoughtfully connected care.</span><span>{{ now()->format('Y') }} · {{ config('app.timezone') }}</span></footer>
</main>
<dialog class="command-dialog" data-command-dialog aria-labelledby="command-title"><div class="command-heading"><x-clinic.icon name="search" /><label id="command-title" class="sr-only" for="command-search">Find a page</label><input id="command-search" type="search" placeholder="Where would you like to go?" autocomplete="off" data-command-search><button type="button" class="key-button" data-command-close>Esc</button></div><div class="command-results">@foreach($links as $key => $label)<a href="{{ $key === 'dashboard' ? route('dashboard') : route('clinic.index',$key) }}" wire:navigate data-command-link><x-clinic.icon :name="$key" /><span>{{ $label }}</span><x-clinic.icon name="arrow" /></a>@endforeach<p class="empty" data-command-empty hidden>No matching pages.</p></div><div class="command-foot">Find your way to better care.<span>Enter to open</span></div></dialog>
<div class="toast" role="status" aria-live="polite" data-toast hidden><span class="toast-icon"><x-clinic.icon name="check" /></span><span data-toast-message></span><button type="button" class="icon-button" aria-label="Dismiss notification" data-toast-close><x-clinic.icon name="close" /></button></div>
@livewireScripts
</body>
</html>
