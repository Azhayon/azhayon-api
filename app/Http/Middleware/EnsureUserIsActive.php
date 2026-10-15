<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->trashed() || $user->isBanned())) {
            $user->tokens()->delete(); // cut off every session immediately

            return response()->json(['message' => 'Account unavailable.'], 403);
        }

        return $next($request);
    }
}