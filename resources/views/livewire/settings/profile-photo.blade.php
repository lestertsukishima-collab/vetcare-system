<div class="profile-photo-editor" data-profile-photo-editor>
    <flux:heading level="3">{{ __('Profile photo') }}</flux:heading>
    <flux:text class="mt-2">{{ __('JPG, PNG, or WebP. Maximum 2 MB. Adjust the circular preview before saving.') }}</flux:text>

    <div class="profile-photo-current">
        <x-account-avatar :user="$user" class="profile-photo-avatar" />
        <div>
            <label for="profile-photo-file" class="profile-photo-label">{{ $user->profile_photo_path ? __('Choose a new photo') : __('Choose a photo') }}</label>
            <input id="profile-photo-file" data-photo-file type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="profile-photo-feedback" />
            @if($user->profile_photo_path)
                <flux:button type="button" variant="ghost" size="sm" data-photo-remove>{{ __('Remove photo') }}</flux:button>
            @endif
        </div>
    </div>

    <x-photo-crop-controls feedback-id="profile-photo-feedback" description="Circular profile photo preview" />

    @error('photo') <p class="profile-photo-error" role="alert">{{ $message }}</p> @enderror
</div>
