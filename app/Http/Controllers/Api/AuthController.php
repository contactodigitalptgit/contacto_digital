<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Token authentication for the mobile event portal. Client accounts remain
 * scoped to their own events, while administrators can inspect every active
 * event through the same read-only reporting API.
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

        $client = $user->client;

        if (! $user->isAdmin() && ! $client) {
            throw ValidationException::withMessages([
                'email' => 'Esta conta nao e uma conta de cliente.',
            ]);
        }

        if (! $user->isAdmin() && ! $client->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Cliente desativado. Entre em contacto com o administrador.',
            ]);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'client' => [
                'id' => $user->isAdmin() ? $user->id : $client->id,
                'name' => $user->isAdmin() ? $user->name : $client->name,
                'business_name' => $user->isAdmin() ? 'Administrador' : $client->business_name,
            ],
            'role' => $user->role,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessao terminada.']);
    }
}
