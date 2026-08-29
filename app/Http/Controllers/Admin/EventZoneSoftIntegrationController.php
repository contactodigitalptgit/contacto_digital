<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\ZoneSoftApplication;
use App\Services\ZoneSoft\ZoneSoftApiException;
use App\Services\ZoneSoft\ZoneSoftDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EventZoneSoftIntegrationController extends Controller
{
    private const DEFAULT_MACHINE_PERMISSIONS = 'API + All document interfaces';

    private const VALIDATE_ALL_DISCOVERY_PAUSE_MICROSECONDS = 500000;

    public function show(Event $event): Response
    {
        $event->load('client.user');
        $application = ZoneSoftApplication::query()->latest('id')->first();

        return Inertia::render('Admin/Events/Integrations', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date->toISOString(),
            ],
            'client' => [
                'id' => $event->client->id,
                'name' => $event->client->name,
                'business_name' => $event->client->business_name,
                'email' => $event->client->user?->email,
            ],
            'application' => $application ? [
                'id' => $application->id,
                'name' => $application->name,
                'base_url' => $application->base_url,
                'app_key' => $application->app_key,
                'has_secret' => $application->hasStoredSecret(),
                'has_usable_secret' => $application->hasReadableSecret(),
                'requires_secret_reconfiguration' => $application->requiresSecretReconfiguration(),
                'is_active' => $application->is_active,
            ] : null,
            'defaultMachinePermissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'machines' => $event->zonesoftMachines()
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

    public function manageTpas(Event $event): Response
    {
        return Inertia::render('Admin/Events/ManageTpas', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date->toISOString(),
            ],
            'machines' => $event->zonesoftMachines()
                ->orderBy('store_id')
                ->get()
                ->map(fn (ClientZoneSoftMachine $machine): array => [
                    'id' => $machine->id,
                    'store_id' => $machine->store_id,
                    'store_label' => $machine->store_label,
                    'license' => $machine->license,
                    'is_active' => $machine->is_active,
                    'last_validated_at' => $machine->last_validated_at?->toISOString(),
                    'last_error' => $machine->last_error,
                ])
                ->values(),
        ]);
    }

    public function saveApplication(Request $request, Event $event): RedirectResponse
    {
        $existing = ZoneSoftApplication::query()->latest('id')->first();
        $secretRequired = ! $existing
            || ! $existing->hasStoredSecret()
            || $existing->requiresSecretReconfiguration();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:255'],
            'app_key' => ['required', 'string', 'max:255'],
            'app_secret' => [$secretRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($existing && $existing->requiresSecretReconfiguration()) {
            ZoneSoftApplication::query()
                ->whereKey($existing->id)
                ->update([
                    'name' => $validated['name'],
                    'base_url' => $validated['base_url'],
                    'app_key' => $validated['app_key'],
                    'app_secret' => Crypt::encryptString($validated['app_secret']),
                    'is_active' => $validated['is_active'],
                    'updated_at' => now(),
                ]);

            return to_route('admin.events.integrations.show', $event);
        }

        $application = $existing ?? new ZoneSoftApplication;
        $application->name = $validated['name'];
        $application->base_url = $validated['base_url'];
        $application->app_key = $validated['app_key'];
        $application->is_active = $validated['is_active'];

        if (filled($validated['app_secret'] ?? null)) {
            $application->app_secret = $validated['app_secret'];
        }

        $application->save();

        return to_route('admin.events.integrations.show', $event);
    }

    public function discoverStores(
        Request $request,
        Event $event,
        ZoneSoftDiscoveryService $discoveryService,
    ): JsonResponse {
        $validated = $request->validate([
            'zs_client_id' => ['required', 'string', 'max:64'],
        ]);

        return response()->json([
            'stores' => $discoveryService->discoverStores(
                $this->getReadableApplication(),
                $validated['zs_client_id'],
            ),
        ]);
    }

    public function validateAllMachines(
        Event $event,
        ZoneSoftDiscoveryService $discoveryService,
    ): JsonResponse {
        return $this->validateMachines(
            $event->zonesoftMachines()->orderBy('store_id')->get(),
            $discoveryService,
            'Nenhum Client ID registado para este evento.',
        );
    }

    public function storeMachine(Request $request, Event $event): RedirectResponse
    {
        $application = $this->getReadableApplication();
        $validated = $request->validate([
            'zs_client_id' => ['required', 'string', 'max:64'],
            'license' => ['nullable', 'string', 'max:64'],
            'store_id' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('client_zonesoft_machines')->where(
                    fn ($query) => $query
                        ->where('event_id', $event->id)
                        ->where('zs_client_id', $request->string('zs_client_id')->toString()),
                ),
            ],
            'store_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $event->zonesoftMachines()->create([
            ...$validated,
            'client_id' => $event->client_id,
            'zonesoft_application_id' => $application->id,
            'permissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'last_validated_at' => now(),
            'last_error' => null,
        ]);

        return to_route('admin.events.integrations.show', $event);
    }

    public function updateMachine(
        Request $request,
        Event $event,
        ClientZoneSoftMachine $machine,
    ): RedirectResponse {
        abort_unless($machine->event_id === $event->id && $machine->client_id === $event->client_id, 404);

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
                            ->where('event_id', $event->id)
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

        return to_route('admin.events.integrations.show', $event);
    }

    public function destroyMachine(Event $event, ClientZoneSoftMachine $machine): RedirectResponse
    {
        abort_unless($machine->event_id === $event->id && $machine->client_id === $event->client_id, 404);

        $machine->delete();

        return to_route('admin.events.integrations.show', $event);
    }

    /**
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     */
    private function validateMachines(
        Collection $machines,
        ZoneSoftDiscoveryService $discoveryService,
        string $emptyMessage,
    ): JsonResponse {
        $application = $this->getReadableApplication();
        $machinesByClientId = $machines->groupBy('zs_client_id');

        abort_if($machinesByClientId->isEmpty(), 422, $emptyMessage);
        $validatedAt = now();
        $validatedCount = 0;
        $failedCount = 0;
        $errors = [];
        $clientIdGroupsCount = $machinesByClientId->count();
        $processedGroups = 0;

        foreach ($machinesByClientId as $zsClientId => $machines) {
            try {
                $stores = collect(
                    $discoveryService->discoverStores($application, (string) $zsClientId),
                )->keyBy('id');

                foreach ($machines as $machine) {
                    $matchedStore = $stores->get($machine->store_id);

                    if (is_array($matchedStore)) {
                        $machine->update([
                            'store_label' => $matchedStore['label'],
                            'last_validated_at' => $validatedAt,
                            'last_error' => null,
                        ]);

                        $validatedCount++;

                        continue;
                    }

                    $message = sprintf(
                        'O Store ID %d nao foi encontrado para o Client ID %s.',
                        $machine->store_id,
                        $machine->zs_client_id,
                    );

                    $machine->update([
                        'last_validated_at' => $validatedAt,
                        'last_error' => $message,
                    ]);

                    $failedCount++;
                    $errors[] = $message;
                }
            } catch (Throwable $exception) {
                $message = $this->resolveMachineValidationErrorMessage($exception);

                foreach ($machines as $machine) {
                    $machine->update([
                        'last_validated_at' => $validatedAt,
                        'last_error' => $message,
                    ]);

                    $failedCount++;
                }

                $errors[] = sprintf('Client ID %s: %s', $zsClientId, $message);
            }

            $processedGroups++;

            if ($processedGroups < $clientIdGroupsCount) {
                usleep(self::VALIDATE_ALL_DISCOVERY_PAUSE_MICROSECONDS);
            }
        }

        $summary = $failedCount === 0
            ? sprintf('%d loja(s) validadas com sucesso.', $validatedCount)
            : sprintf('%d loja(s) validadas e %d com erro.', $validatedCount, $failedCount);

        return response()->json([
            'message' => $summary,
            'validated' => $validatedCount,
            'failed' => $failedCount,
            'errors' => array_values(array_unique($errors)),
        ]);
    }

    private function resolveMachineValidationErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ZoneSoftApiException && $exception->isRateLimited()) {
            return 'A ZoneSoft limitou temporariamente os pedidos deste Client ID. Tente validar novamente dentro de alguns instantes.';
        }

        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Nao foi possivel validar as lojas deste Client ID.';
    }

    private function getReadableApplication(): ZoneSoftApplication
    {
        $application = ZoneSoftApplication::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();

        abort_unless($application, 422, 'Configure primeiro a aplicacao ZoneSoft.');
        abort_if(
            ! $application->hasReadableSecret(),
            422,
            'O APP-SECRET precisa de ser configurado novamente para esta base.',
        );

        return $application;
    }
}
