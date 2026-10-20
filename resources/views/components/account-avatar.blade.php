@props(['user', 'photoUrl' => null])
@php($avatarUrl = $photoUrl ?? $user->profilePhotoUrl())

<span {{ $attributes->class(['account-avatar']) }} data-account-avatar data-photo-owner="{{ $user->id }}" role="img" aria-label="{{ $user->name }} profile photo">
    @if($avatarUrl)
        <img src="{{ $avatarUrl }}" alt="" width="96" height="96">
    @else
        <span>{{ $user->initials() }}</span>
    @endif
</span>
