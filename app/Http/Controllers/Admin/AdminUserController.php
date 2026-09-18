<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        $admins = User::query()
            ->where('role', 'admin')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at']);

        return Inertia::render('Admin/Users/Index', [
            'admins' => $admins,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        return to_route('admin.users.index');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'admin', 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        $user->update($data);

        return to_route('admin.users.index');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'admin', 404);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'id' => 'Não pode eliminar a sua própria conta.',
            ]);
        }

        if (User::where('role', 'admin')->count() <= 1) {
            throw ValidationException::withMessages([
                'id' => 'Tem de existir pelo menos um administrador.',
            ]);
        }

        $user->delete();

        return to_route('admin.users.index');
    }
}
