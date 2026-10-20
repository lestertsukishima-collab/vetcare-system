<div class="profile-photo-editor pet-photo-editor" data-profile-photo-editor data-photo-kind="pet" data-photo-label="Pet photo">
    <h4>{{ $pet->name }}'s photo</h4>
    <p class="helper">JPG, PNG, or WebP. Maximum 2 MB. Adjust the circular preview before saving. The veterinarians assigned to your pet can see this photo.</p>

    <div class="profile-photo-current">
        <x-pet-avatar :pet="$pet" class="profile-photo-avatar" />
        <div>
            <label for="pet-photo-file-{{ $pet->id }}" class="profile-photo-label">{{ $pet->photo_path ? 'Choose a new photo' : 'Choose a photo' }}</label>
            <input id="pet-photo-file-{{ $pet->id }}" data-photo-file type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="pet-photo-feedback-{{ $pet->id }}" />
            @if($pet->photo_path)
                <button type="button" class="small secondary btn btn-outline-secondary btn-sm" data-photo-remove>Remove photo</button>
            @endif
        </div>
    </div>

    <x-photo-crop-controls :feedback-id="'pet-photo-feedback-'.$pet->id" :description="'Circular photo preview for '.$pet->name" />
    @error('photo') <p class="profile-photo-error" role="alert">{{ $message }}</p> @enderror
</div>
