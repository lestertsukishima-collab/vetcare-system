@props(['name', 'label', 'type' => 'text', 'value' => '', 'required' => false])
@aware(['formKey' => null, 'reactive' => false, 'values' => []])
@php($fieldValue = $formKey && old('_form_key') === $formKey ? old($name, $value) : $value)
@php($fieldValue = $reactive ? ($values[$name] ?? $fieldValue) : $fieldValue)
@php($fieldValue = is_scalar($fieldValue) || is_null($fieldValue) ? $fieldValue : '')
<label>{{ $label }}
@if($type === 'textarea')<textarea name="{{ $name }}" @if($reactive) wire:model="data.{{ $name }}" @endif {{ $attributes }} @required($required)>{{ $fieldValue }}</textarea>
@else<input type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : $fieldValue }}" @if($reactive) wire:model="data.{{ $name }}" @endif {{ $attributes }} @required($required)>@endif
@error($name)<span class="field-error">{{ $message }}</span>@enderror
</label>
