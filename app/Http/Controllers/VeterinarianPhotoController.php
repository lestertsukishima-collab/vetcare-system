<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Veterinarian;
use App\Services\ClinicAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VeterinarianPhotoController extends Controller
{
    public function __invoke(Request $request, Veterinarian $veterinarian, string $version): StreamedResponse
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);
        app(ClinicAccess::class)->authorize($viewer, 'appointments');

        $doctor = $veterinarian->user;
        abort_unless($doctor->active && $doctor->role === 'veterinarian', 404);
        $path = $doctor->profile_photo_path;
        abort_unless($path && hash_equals(basename($path), $version) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
