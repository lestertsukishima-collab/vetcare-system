<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>@include('partials.head')</head>
    <body class="auth-body">
        <div class="auth-shell">
            <section class="auth-story" aria-label="Welcome to VetCare">
                <a href="{{ route('home') }}" class="auth-brand" wire:navigate><span><x-clinic.icon name="pets" /></span>VetCare.</a>
                <div class="auth-story-copy"><p class="auth-kicker">THOUGHTFULLY CONNECTED CARE</p><h1>For the lives<br>that fill ours.</h1><p>A calm, connected space for your clinic, your team, and every companion in your care.</p><x-clinic.care-art class="auth-art" /><div class="auth-caption"><span>♡</span> A little care. A lifetime of difference.</div></div>
                <p class="auth-footnote">Appointments · Health records · Preventive care</p>
            </section>
            <main class="auth-form-side"><a href="{{ route('home') }}" class="auth-back" wire:navigate>← Back to VetCare</a><div class="auth-form-card">{{ $slot }}</div><p class="auth-form-foot">A healthier tomorrow starts with thoughtful care.</p></main>
        </div>
        @persist('toast')<flux:toast.group><flux:toast /></flux:toast.group>@endpersist
        @fluxScripts
    </body>
</html>
