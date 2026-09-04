<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Token auth for the client-facing mobile app only (see
 * docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md — app Flutter, cliente
 * acompanhar o evento). Deliberately separate from the web session guard:
 * an admin logging in here would be a mistake, not a feature, so it is
 * rejected outright rather than silently issuing a token nobody meant to
 * hand a mobile client.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Auth::once() checks the credentials and hydrates Auth::user()
        // for the rest of this request without touching any session —
        // there is no web session to create for a token client.
        if (! Auth::once($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais invalidas.',
            ]);
        }

        $user = Auth::user();

        if (! $user->client) {
            throw ValidationException::withMessages([
                'email' => 'Esta conta nao e uma conta de cliente.',
            ]);
        }

        if (! $user->client->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Cliente desativado. Entre em contacto com o administrador.',
            ]);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'client' => [
                'id' => $user->client->id,
                'name' => $user->client->name,
                'business_name' => $user->client->business_name,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessao terminada.']);
    }
}
