<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as FileValidator;

class SquarePhotoUploads
{
    public function store(mixed $photo, string $directory): string
    {
        Validator::make(['photo' => $photo], [
            'photo' => ['bail', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'photo.required' => 'Choose a photo first.',
            'photo.image' => 'Choose a valid JPG, PNG, or WebP image.',
            'photo.mimes' => 'Choose a JPG, PNG, or WebP image.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
        ])->after(function (FileValidator $validator) use ($photo): void {
            if ($validator->errors()->has('photo') || ! $photo instanceof UploadedFile) {
                return;
            }

            $dimensions = @getimagesize($photo->getPathname());
            if ($dimensions === false || $dimensions[0] !== $dimensions[1]) {
                $validator->errors()->add('photo', 'Crop the photo to a square (1:1) before saving.');
            }
        })->validate();

        if (! $photo instanceof UploadedFile) {
            throw ValidationException::withMessages(['photo' => 'Choose a valid image file.']);
        }

        $path = $photo->store($directory, 'local');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['photo' => 'The photo could not be stored. Please try again.']);
        }

        return $path;
    }
}
