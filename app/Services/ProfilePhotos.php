<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProfilePhotos
{
    public function __construct(private SquarePhotoUploads $uploads) {}

    public function replace(User $user, mixed $photo): User
    {
        $path = $this->uploads->store($photo, 'profile-photos/'.$user->id);

        try {
            [$current, $previous] = DB::transaction(function () use ($user, $path): array {
                $current = User::query()->lockForUpdate()->findOrFail($user->id);
                $previous = $current->profile_photo_path;
                $current->forceFill(['profile_photo_path' => $path])->save();

                return [$current, $previous];
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $current;
    }

    public function remove(User $user): User
    {
        [$current, $previous] = DB::transaction(function () use ($user): array {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $previous = $current->profile_photo_path;
            $current->forceFill(['profile_photo_path' => null])->save();

            return [$current, $previous];
        });

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $current;
    }
}
