@props(['name', 'label', 'options', 'value' => '', 'required' => false])
@aware(['formKey' => null, 'reactive' => false, 'values' => []])
@php($selectedValue = $formKey && old('_form_key') === $formKey ? old($name, $value) : $value)
@php($selectedValue = $reactive ? ($values[$name] ?? $selectedValue) : $selectedValue)
@php($selectedValue = is_scalar($selectedValue) || is_null($selectedValue) ? $selectedValue : '')
<label>{{ $label }}<select name="{{ $name }}" @if($reactive) @if($name === 'role') wire:model.live="data.{{ $name }}" @else wire:model="data.{{ $name }}" @endif @endif {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false']) }} @required($required)>
@foreach($options as $optionValue=>$optionLabel)<option value="{{ $optionValue }}" @selected((string) $selectedValue === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
</select>@error($name)<span class="field-error">{{ $message }}</span>@enderror</label>
