<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        $clients = Client::query()
            ->with('user')
            ->withCount('events')
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'business_name' => $client->business_name,
                'address' => $client->address,
                'phone' => $client->phone,
                'email' => $client->user->email,
                'events_count' => $client->events_count,
                'is_active' => $client->is_active,
            ]);

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Clients/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        DB::transaction(function () use ($validated): void {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'email_verified_at' => now(),
                'role' => 'client',
            ]);

            Client::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'business_name' => $validated['business_name'] ?? null,
                'address' => $validated['address'],
                'phone' => $validated['phone'],
                'is_active' => true,
            ]);
        });

        return to_route('admin.clients.index');
    }

    public function edit(Client $client): Response
    {
        $client->load('user');

        return Inertia::render('Admin/Clients/Edit', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'business_name' => $client->business_name,
                'address' => $client->address,
                'phone' => $client->phone,
                'email' => $client->user->email,
            ],
        ]);
    }

    public function dashboard(Client $client): Response
    {
        $client->load([
            'events' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('event_date'),
        ]);

        return Inertia::render('Dashboard', [
            'type' => 'client',
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'business_name' => $client->business_name,
                'address' => $client->address,
                'phone' => $client->phone,
            ],
            'events' => $client->events->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->toISOString(),
            ]),
            'previewMode' => true,
            'previewBackUrl' => route('admin.clients.index'),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->load('user');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($client->user->id)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        DB::transaction(function () use ($client, $validated): void {
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
            ];

            if (! empty($validated['password'])) {
                $userData['password'] = $validated['password'];
            }

            $client->user->update($userData);

            $client->update([
                'name' => $validated['name'],
                'business_name' => $validated['business_name'] ?? null,
                'address' => $validated['address'],
                'phone' => $validated['phone'],
            ]);
        });

        return to_route('admin.clients.index');
    }

    public function toggleStatus(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $client->update([
            'is_active' => $validated['is_active'],
        ]);

        return to_route('admin.clients.index');
    }

    public function destroy(Client $client): RedirectResponse
    {
        DB::transaction(function () use ($client): void {
            $client->load('user');

            if ($client->user) {
                $client->user->delete();

                return;
            }

            $client->delete();
        });

        return to_route('admin.clients.index');
    }
}
