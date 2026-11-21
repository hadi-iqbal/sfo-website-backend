<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active, 403, 'This account is inactive.');
        abort_if($user->requires_profile_setup || ! $user->tokenCan('portal'), 403, 'Complete your profile setup to continue.');

        return $next($request);
    }
}
