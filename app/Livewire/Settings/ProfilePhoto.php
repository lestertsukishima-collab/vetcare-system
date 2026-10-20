<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Services\ProfilePhotos;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ProfilePhoto extends Component
{
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $photo;

    public function savePhoto(ProfilePhotos $photos): bool
    {
        $user = $photos->replace($this->user(), $this->photo);
        if ($this->photo instanceof TemporaryUploadedFile) {
            $this->photo->delete();
        }
        $this->reset('photo');
        $this->resetValidation('photo');
        $this->photoChanged($user);
        Flux::toast(variant: 'success', text: __('Profile photo saved.'));

        return true;
    }

    public function removePhoto(ProfilePhotos $photos): void
    {
        $user = $photos->remove($this->user());
        if ($this->photo instanceof TemporaryUploadedFile) {
            $this->photo->delete();
        }
        $this->reset('photo');
        $this->resetValidation('photo');
        $this->photoChanged($user);
        Flux::toast(variant: 'success', text: __('Profile photo removed.'));
    }

    private function photoChanged(User $user): void
    {
        Auth::setUser($user);
        $this->dispatch('profile-photo-updated', userId: (string) $user->id, url: $user->profilePhotoUrl(), initials: $user->initials());
    }

    private function user(): User
    {
        abort_unless(Auth::check(), 401);

        return User::query()->findOrFail(Auth::id());
    }

    public function render(): View
    {
        return view('livewire.settings.profile-photo', ['user' => $this->user()]);
    }
}
