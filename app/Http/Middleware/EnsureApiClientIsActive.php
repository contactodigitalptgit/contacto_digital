<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart to EnsureClientIsActive: same rule (a client deactivated
 * after issuing a token loses access immediately), but a token-authenticated
 * request has no session to invalidate and must get a JSON 403, not a
 * redirect to the (web-only) login route.
 */
class EnsureApiClientIsActive
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->client && ! $user->client->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Cliente desativado. Entre em contacto com o administrador.',
            ], 403);
        }

        return $next($request);
    }
}
