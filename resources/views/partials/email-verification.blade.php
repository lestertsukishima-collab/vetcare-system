@if (request()->boolean('verified') && auth()->user()?->hasVerifiedEmail())
    <meta name="email-verification-complete" content="{{ auth()->id() }}" />
@endif
<script src="{{ asset('email-verification.js') }}?v={{ filemtime(public_path('email-verification.js')) }}" defer data-navigate-once></script>
