@props(['action', 'method' => 'POST', 'formKey', 'reactive' => false, 'values' => []])
<form method="POST" action="{{ $action }}" data-form-key="{{ $formKey }}" @if($reactive) wire:submit="save" @endif {{ $attributes }}>
    @csrf
    @if(strtoupper($method) !== 'POST') @method($method) @endif
    <input type="hidden" name="_form_key" value="{{ $formKey }}">
    {{ $slot }}
    @if($reactive)
        <div class="wide form-feedback" aria-live="polite">
            <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving your changes…</span>
        </div>
    @endif
</form>
