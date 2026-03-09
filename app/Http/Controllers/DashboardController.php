<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $recentClients = Client::query()
                ->withCount([
                    'events as events_count' => fn ($query) => $query->where('is_active', true),
                ])
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'business_name' => $client->business_name,
                    'events_count' => $client->events_count,
                ]);

            $upcomingEvents = Event::query()
                ->with('client')
                ->where('is_active', true)
                ->where('event_date', '>=', now())
                ->orderBy('event_date')
                ->limit(5)
                ->get()
                ->map(fn (Event $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'event_date' => $event->event_date->toISOString(),
                    'client_name' => $event->client->name,
                ]);

            return Inertia::render('Dashboard', [
                'type' => 'admin',
                'stats' => [
                    'clients' => Client::count(),
                    'events' => Event::query()->where('is_active', true)->count(),
                    'upcoming' => Event::query()
                        ->where('is_active', true)
                        ->where('event_date', '>=', now())
                        ->count(),
                ],
                'recentClients' => $recentClients,
                'upcomingEvents' => $upcomingEvents,
            ]);
        }

        $client = $user->client()
            ->with([
                'events' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('event_date'),
            ])
            ->firstOrFail();

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
        ]);
    }
}
