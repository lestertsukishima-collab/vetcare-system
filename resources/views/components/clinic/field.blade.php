@props(['name', 'label', 'type' => 'text', 'value' => '', 'required' => false])
@aware(['formKey' => null, 'reactive' => false, 'values' => []])
@php($fieldValue = $formKey && old('_form_key') === $formKey ? old($name, $value) : $value)
@php($fieldValue = $reactive ? ($values[$name] ?? $fieldValue) : $fieldValue)
@php($fieldValue = is_scalar($fieldValue) || is_null($fieldValue) ? $fieldValue : '')
@php($controlClass = in_array($type, ['checkbox', 'radio']) ? 'form-check-input' : 'form-control')
<label>{{ $label }}
@if($type === 'textarea')<textarea name="{{ $name }}" @if($reactive) wire:model="data.{{ $name }}" @endif {{ $attributes->class([$controlClass, 'is-invalid' => $errors->has($name)])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false']) }} @required($required)>{{ $fieldValue }}</textarea>
@else<input type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : $fieldValue }}" @if($reactive) wire:model="data.{{ $name }}" @endif {{ $attributes->class([$controlClass, 'is-invalid' => $errors->has($name)])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false']) }} @required($required)>@endif
@error($name)<span class="field-error">{{ $message }}</span>@enderror
</label>
