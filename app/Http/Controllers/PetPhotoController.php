<?php

namespace App\Http\Controllers;

use App\Models\Pet;
use App\Models\User;
use App\Services\PetPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PetPhotoController extends Controller
{
    public function __invoke(Request $request, Pet $pet, string $version): StreamedResponse
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);
        $pet = app(PetPhotos::class)->forViewing($viewer, $pet->id);
        $path = $pet->photo_path;
        abort_unless($path && hash_equals(basename($path), $version) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
