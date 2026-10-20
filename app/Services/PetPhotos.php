<?php

namespace App\Services;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PetPhotos
{
    public function __construct(private SquarePhotoUploads $uploads) {}

    public function forViewing(User $actor, int $petId): Pet
    {
        app(ClinicAccess::class)->authorize($actor, 'pets');

        return Pet::query()
            ->when($actor->role === 'owner', fn ($query) => $query->whereHas('owner', fn ($owner) => $owner->where('user_id', $actor->id)))
            ->when($actor->role === 'veterinarian', fn ($query) => $query->whereHas('appointments.veterinarian', fn ($vet) => $vet->where('user_id', $actor->id)))
            ->findOrFail($petId);
    }

    public function forEditing(User $actor, int $petId): Pet
    {
        return $this->editableQuery($actor)->findOrFail($petId);
    }

    public function replace(User $actor, int $petId, mixed $photo): Pet
    {
        $this->forEditing($actor, $petId);
        $path = $this->uploads->store($photo, 'pet-photos/'.$petId);

        try {
            [$pet, $previous] = DB::transaction(function () use ($actor, $petId, $path): array {
                $pet = $this->editableQuery($actor)->lockForUpdate()->findOrFail($petId);
                $previous = $pet->photo_path;
                $pet->forceFill(['photo_path' => $path])->save();

                return [$pet, $previous];
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $pet;
    }

    public function remove(User $actor, int $petId): Pet
    {
        [$pet, $previous] = DB::transaction(function () use ($actor, $petId): array {
            $pet = $this->editableQuery($actor)->lockForUpdate()->findOrFail($petId);
            $previous = $pet->photo_path;
            $pet->forceFill(['photo_path' => null])->save();

            return [$pet, $previous];
        });

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $pet;
    }

    /** @return Builder<Pet> */
    private function editableQuery(User $actor): Builder
    {
        app(ClinicAccess::class)->authorize($actor, 'pets');
        abort_unless(in_array($actor->role, ['admin', 'owner']), 403);

        return Pet::query()->when($actor->role === 'owner', fn ($query) => $query->whereHas('owner', fn ($owner) => $owner->where('user_id', $actor->id)));
    }
}
