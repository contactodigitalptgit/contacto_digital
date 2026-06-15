<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\ZoneSoftApplication;
use App\Services\ZoneSoft\ZoneSoftDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientZoneSoftIntegrationController extends Controller
{
    private const DEFAULT_MACHINE_PERMISSIONS = 'API + All document interfaces';

    public function show(Client $client): Response
    {
        $client->load('user');
        $application = ZoneSoftApplication::query()->latest('id')->first();

        return Inertia::render('Admin/Clients/Integrations', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'business_name' => $client->business_name,
                'email' => $client->user?->email,
            ],
            'application' => $application ? [
                'id' => $application->id,
                'name' => $application->name,
                'base_url' => $application->base_url,
                'app_key' => $application->app_key,
                'has_secret' => filled($application->app_secret),
                'is_active' => $application->is_active,
            ] : null,
            'defaultMachinePermissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'machines' => $client->zonesoftMachines()
                ->orderBy('store_id')
                ->get()
                ->map(fn (ClientZoneSoftMachine $machine): array => [
                    'id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'license' => $machine->license,
                    'store_id' => $machine->store_id,
                    'store_label' => $machine->store_label,
                    'permissions' => $machine->permissions ?: self::DEFAULT_MACHINE_PERMISSIONS,
                    'is_active' => $machine->is_active,
                    'last_validated_at' => $machine->last_validated_at?->toISOString(),
                    'last_error' => $machine->last_error,
                ])
                ->values(),
        ]);
    }

    public function saveApplication(Request $request, Client $client): RedirectResponse
    {
        $existing = ZoneSoftApplication::query()->latest('id')->first();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:255'],
            'app_key' => ['required', 'string', 'max:255'],
            'app_secret' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $application = $existing ?? new ZoneSoftApplication();
        $application->name = $validated['name'];
        $application->base_url = $validated['base_url'];
        $application->app_key = $validated['app_key'];
        $application->is_active = $validated['is_active'];

        if (filled($validated['app_secret'] ?? null)) {
            $application->app_secret = $validated['app_secret'];
        }

        $application->save();

        return to_route('admin.clients.integrations.show', $client);
    }

    public function discoverStores(
        Request $request,
        Client $client,
        ZoneSoftDiscoveryService $discoveryService,
    ): JsonResponse {
        $validated = $request->validate([
            'zs_client_id' => ['required', 'string', 'max:64'],
        ]);

        $application = ZoneSoftApplication::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();

        abort_unless($application, 422, 'Configure primeiro a aplicacao ZoneSoft.');

        return response()->json([
            'stores' => $discoveryService->discoverStores($application, $validated['zs_client_id']),
        ]);
    }

    public function storeMachine(Request $request, Client $client): RedirectResponse
    {
        $application = ZoneSoftApplication::query()
            ->where('is_active', true)
            ->latest('id')
            ->firstOrFail();

        $validated = $request->validate([
            'zs_client_id' => ['required', 'string', 'max:64'],
            'license' => ['nullable', 'string', 'max:64'],
            'store_id' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('client_zonesoft_machines')->where(
                    fn ($query) => $query
                        ->where('client_id', $client->id)
                        ->where('zs_client_id', $request->string('zs_client_id')->toString()),
                ),
            ],
            'store_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $client->zonesoftMachines()->create([
            ...$validated,
            'zonesoft_application_id' => $application->id,
            'permissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'last_validated_at' => now(),
            'last_error' => null,
        ]);

        return to_route('admin.clients.integrations.show', $client);
    }

    public function updateMachine(
        Request $request,
        Client $client,
        ClientZoneSoftMachine $machine,
    ): RedirectResponse {
        abort_unless($machine->client_id === $client->id, 404);

        $validated = $request->validate([
            'zs_client_id' => ['required', 'string', 'max:64'],
            'license' => ['nullable', 'string', 'max:64'],
            'store_id' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('client_zonesoft_machines')
                    ->ignore($machine->id)
                    ->where(
                        fn ($query) => $query
                            ->where('client_id', $client->id)
                            ->where('zs_client_id', $request->string('zs_client_id')->toString()),
                    ),
            ],
            'store_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $machine->update([
            ...$validated,
            'permissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'last_validated_at' => now(),
            'last_error' => null,
        ]);

        return to_route('admin.clients.integrations.show', $client);
    }

    public function destroyMachine(Client $client, ClientZoneSoftMachine $machine): RedirectResponse
    {
        abort_unless($machine->client_id === $client->id, 404);

        $machine->delete();

        return to_route('admin.clients.integrations.show', $client);
    }
}
