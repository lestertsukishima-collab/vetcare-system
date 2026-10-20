<?php

namespace App\Livewire\Clinic;

use App\Models\Pet;
use App\Models\User;
use App\Services\PetPhotos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class PetPhoto extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $petId;

    /** @var TemporaryUploadedFile|null */
    public $photo;

    public function mount(int $petId): void
    {
        $this->petId = $petId;
        app(PetPhotos::class)->forEditing($this->actor(), $petId);
    }

    public function savePhoto(PetPhotos $photos): bool
    {
        $pet = $photos->replace($this->actor(), $this->petId, $this->photo);
        $this->photoChanged($pet, 'Pet photo saved.');

        return true;
    }

    public function removePhoto(PetPhotos $photos): void
    {
        $pet = $photos->remove($this->actor(), $this->petId);
        $this->photoChanged($pet, 'Pet photo removed.');
    }

    private function photoChanged(Pet $pet, string $message): void
    {
        if ($this->photo instanceof TemporaryUploadedFile) {
            $this->photo->delete();
        }
        $this->reset('photo');
        $this->resetValidation('photo');
        $this->dispatch('pet-photo-updated', petId: (string) $pet->id, url: $pet->photoUrl());
        $this->dispatch('clinic-toast', message: $message);
    }

    private function actor(): User
    {
        abort_unless(auth()->check(), 401);

        return User::query()->findOrFail(auth()->id());
    }

    public function render(): View
    {
        return view('livewire.clinic.pet-photo', ['pet' => app(PetPhotos::class)->forEditing($this->actor(), $this->petId)]);
    }
}
