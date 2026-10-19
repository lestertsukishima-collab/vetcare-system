<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'user_id' => (string) $user->getAuthIdentifier(),
            'verified' => $user->hasVerifiedEmail(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
