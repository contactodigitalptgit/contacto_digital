<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\DashboardConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventDashboardConfigurationController extends Controller
{
    public function edit(
        Event $event,
        DashboardConfigurationService $configurationService,
    ): Response {
        $event->load('client');

        return Inertia::render('Admin/Events/DashboardConfigurationEdit', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'client_name' => $event->client->name,
                'event_date' => $event->event_date->toISOString(),
            ],
            'configuration' => $configurationService->resolve($event),
            'presets' => $configurationService->presets($event),
            'updateUrl' => route('admin.events.dashboard-configuration.update', $event),
            'dashboardUrl' => route('admin.events.dashboard', $event),
        ]);
    }

    public function update(
        Request $request,
        Event $event,
        DashboardConfigurationService $configurationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'configuration' => ['nullable', 'array'],
        ]);

        $configuration = $validated['configuration'] ?? null;

        $event->update([
            'dashboard_configuration' => is_array($configuration)
                ? $configurationService->normalize($event, $configuration)
                : null,
        ]);

        return back()->with('success', 'Apresentação do dashboard atualizada.');
    }
}
