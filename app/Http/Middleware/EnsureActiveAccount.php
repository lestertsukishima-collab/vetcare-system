<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->check($request);
        $response = $next($request);
        // Also covers authentication completed during this request, including passkeys and 2FA.
        $this->check($request);

        return $response;
    }

    private function check(Request $request): void
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->fresh()?->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'This account is inactive. Contact the clinic administrator.');
        }
    }
}
