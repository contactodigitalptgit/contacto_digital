<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\DashboardConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventDashboardConfigurationController extends Controller
{
    public function __invoke(
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
