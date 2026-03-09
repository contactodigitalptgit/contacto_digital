<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(): Response
    {
        $events = Event::query()
            ->with('client')
            ->orderByDesc('is_active')
            ->orderBy('event_date')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->toISOString(),
                'event_date_input' => $event->event_date->format('Y-m-d\TH:i'),
                'client_name' => $event->client->name,
                'client_id' => $event->client_id,
                'is_active' => $event->is_active,
            ]);

        return Inertia::render('Admin/Events/Index', [
            'events' => $events,
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Events/Create', [
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['required', 'date'],
        ]);

        Event::create($validated);

        return to_route('admin.events.index');
    }

    public function edit(Event $event): Response
    {
        return Inertia::render('Admin/Events/Edit', [
            'event' => [
                'id' => $event->id,
                'client_id' => $event->client_id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->format('Y-m-d\TH:i'),
            ],
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['required', 'date'],
        ]);

        $event->update($validated);

        return to_route('admin.events.index');
    }

    public function toggleStatus(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $event->update([
            'is_active' => $validated['is_active'],
        ]);

        return to_route('admin.events.index');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $event->delete();

        return to_route('admin.events.index');
    }
}
