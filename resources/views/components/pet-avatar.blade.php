@props(['pet'])

<span {{ $attributes->class(['patient-avatar', 'pet-photo']) }} data-pet-avatar data-photo-pet="{{ $pet->id }}" role="img" aria-label="{{ $pet->name }} photo">
    <span class="pet-photo-content" data-pet-avatar-content>
        @if($pet->photoUrl())
            <img src="{{ $pet->photoUrl() }}" alt="" width="96" height="96">
        @else
            <x-clinic.icon name="pets" />
        @endif
    </span>
    <template data-pet-photo-fallback><x-clinic.icon name="pets" /></template>
</span>
