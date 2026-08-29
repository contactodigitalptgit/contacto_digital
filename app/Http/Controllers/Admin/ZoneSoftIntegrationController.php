<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ZoneSoftMachineBulkImportRequest;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\ZoneSoftApplication;
use App\Services\ZoneSoft\ZoneSoftApiException;
use App\Services\ZoneSoft\ZoneSoftDiscoveryService;
use App\Services\ZoneSoft\ZoneSoftMachineBulkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ZoneSoftIntegrationController extends Controller
{
    private const DEFAULT_MACHINE_PERMISSIONS = 'API + All document interfaces';

    private const VALIDATE_ALL_DISCOVERY_PAUSE_MICROSECONDS = 500000;

    public function index(): Response
    {
        $application = ZoneSoftApplication::query()->latest('id')->first();
        $applications = ZoneSoftApplication::query()->orderBy('name')->get();

        return Inertia::render('Admin/Integrations/ZoneSoft', [
            'application' => $application ? [
                'id' => $application->id,
                'name' => $application->name,
                'external_id' => $application->external_id,
                'base_url' => $application->base_url,
                'app_key' => $application->app_key,
                'has_secret' => $application->hasStoredSecret(),
                'has_usable_secret' => $application->hasReadableSecret(),
                'requires_secret_reconfiguration' => $application->requiresSecretReconfiguration(),
                'is_active' => $application->is_active,
            ] : null,
            'applications' => $applications->map(fn (ZoneSoftApplication $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'external_id' => $item->external_id,
                'base_url' => $item->base_url,
                'app_key' => $item->app_key,
                'has_secret' => $item->hasStoredSecret(),
                'has_usable_secret' => $item->hasReadableSecret(),
                'requires_secret_reconfiguration' => $item->requiresSecretReconfiguration(),
                'is_active' => $item->is_active,
            ])->values(),
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
            'defaultMachinePermissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'machines' => ClientZoneSoftMachine::query()
                ->with(['application:id,name', 'client:id,name', 'events:id,title'])
                ->orderBy('client_id')
                ->orderBy('license')
                ->orderBy('store_id')
                ->get()
                ->map(fn (ClientZoneSoftMachine $machine): array => [
                    'id' => $machine->id,
                    'application_id' => $machine->zonesoft_application_id,
                    'application_name' => $machine->application?->name,
                    'client_id' => $machine->client_id,
                    'client_name' => $machine->client->name,
                    'zs_client_id' => $machine->zs_client_id,
                    'license' => $machine->license,
                    'store_id' => $machine->store_id,
                    'store_label' => $machine->store_label,
                    'permissions' => $machine->permissions ?: self::DEFAULT_MACHINE_PERMISSIONS,
                    'is_active' => $machine->is_active,
                    'last_validated_at' => $machine->last_validated_at?->toISOString(),
                    'last_error' => $machine->last_error,
                    'events' => $machine->events
                        ->map(fn ($event): array => ['id' => $event->id, 'title' => $event->title])
                        ->values(),
                ])
                ->values(),
        ]);
    }

    public function saveApplication(Request $request): RedirectResponse
    {
        $creatingNew = $request->boolean('create_new');
        $existing = $creatingNew
            ? null
            : ($request->filled('application_id')
                ? ZoneSoftApplication::query()->find($request->integer('application_id'))
                : ZoneSoftApplication::query()->latest('id')->first());
        $secretRequired = ! $existing
            || ! $existing->hasStoredSecret()
            || $existing->requiresSecretReconfiguration();

        $validated = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:zonesoft_applications,id'],
            'create_new' => ['nullable', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'external_id' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('zonesoft_applications', 'external_id')->ignore($existing?->id),
            ],
            'base_url' => ['required', 'url', 'max:255'],
            'app_key' => ['required', 'string', 'max:255'],
            'app_secret' => [$secretRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($existing && $existing->requiresSecretReconfiguration()) {
            ZoneSoftApplication::query()->whereKey($existing->id)->update([
                'name' => $validated['name'],
                'external_id' => ($validated['external_id'] ?? null) ?: null,
                'base_url' => $validated['base_url'],
                'app_key' => $validated['app_key'],
                'app_secret' => Crypt::encryptString($validated['app_secret']),
                'is_active' => $validated['is_active'],
                'updated_at' => now(),
            ]);

            return to_route('admin.integrations.zonesoft.index');
        }

        $application = $existing ?? new ZoneSoftApplication;
        $application->fill([
            'name' => $validated['name'],
            'external_id' => ($validated['external_id'] ?? null) ?: null,
            'base_url' => $validated['base_url'],
            'app_key' => $validated['app_key'],
            'is_active' => $validated['is_active'],
        ]);

        if (filled($validated['app_secret'] ?? null)) {
            $application->app_secret = $validated['app_secret'];
        }

        $application->save();

        return to_route('admin.integrations.zonesoft.index');
    }

    public function discoverStores(
        Request $request,
        ZoneSoftDiscoveryService $discoveryService,
    ): JsonResponse {
        $validated = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:zonesoft_applications,id'],
            'zs_client_id' => ['required', 'string', 'max:64'],
        ]);

        return response()->json([
            'stores' => $discoveryService->discoverStores(
                $this->getReadableApplication(
                    isset($validated['application_id']) ? (int) $validated['application_id'] : null,
                ),
                $validated['zs_client_id'],
            ),
        ]);
    }

    public function validateAllMachines(
        Request $request,
        ZoneSoftDiscoveryService $discoveryService,
    ): JsonResponse {
        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);
        $machines = ClientZoneSoftMachine::query()
            ->when(
                isset($validated['client_id']),
                fn ($query) => $query->where('client_id', $validated['client_id']),
            )
            ->orderBy('client_id')
            ->orderBy('store_id')
            ->get();

        return $this->validateMachines(
            $machines,
            $discoveryService,
            'Nenhum Client ID global registado para validar.',
        );
    }

    public function previewMachineImport(
        ZoneSoftMachineBulkImportRequest $request,
        ZoneSoftMachineBulkImportService $importService,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json($importService->preview(
            Client::query()->findOrFail($validated['client_id']),
            $this->resolveImportApplication($validated['payload']),
            $validated['payload'],
        ));
    }

    public function importMachines(
        ZoneSoftMachineBulkImportRequest $request,
        ZoneSoftMachineBulkImportService $importService,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json($importService->import(
            Client::query()->findOrFail($validated['client_id']),
            $this->resolveImportApplication($validated['payload']),
            $validated['payload'],
        ));
    }

    public function storeMachine(Request $request): RedirectResponse
    {
        $application = $this->getReadableApplication(
            $request->filled('application_id') ? $request->integer('application_id') : null,
        );
        $validated = $this->validateMachine($request, $application);

        ClientZoneSoftMachine::query()->create([
            ...$validated,
            'zonesoft_application_id' => $application->id,
            'permissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'last_validated_at' => now(),
            'last_error' => null,
        ]);

        return to_route('admin.integrations.zonesoft.index');
    }

    public function updateMachine(
        Request $request,
        ClientZoneSoftMachine $machine,
    ): RedirectResponse {
        if ($machine->events()->exists() && $request->integer('client_id') !== $machine->client_id) {
            throw ValidationException::withMessages([
                'client_id' => 'Não pode alterar o cliente de um TPA que já está associado a eventos.',
            ]);
        }

        $application = $machine->application ?? $this->getReadableApplication();
        $validated = $this->validateMachine($request, $application, $machine);

        $machine->update([
            ...$validated,
            'permissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'last_validated_at' => now(),
            'last_error' => null,
        ]);

        return to_route('admin.integrations.zonesoft.index');
    }

    public function destroyMachine(ClientZoneSoftMachine $machine): RedirectResponse
    {
        if ($machine->events()->exists()) {
            throw ValidationException::withMessages([
                'machine' => 'Remova primeiro este TPA dos eventos associados.',
            ]);
        }

        $machine->delete();

        return to_route('admin.integrations.zonesoft.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMachine(
        Request $request,
        ZoneSoftApplication $application,
        ?ClientZoneSoftMachine $machine = null,
    ): array {
        return $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'zs_client_id' => ['required', 'string', 'max:64'],
            'license' => ['required', 'string', 'max:64'],
            'store_id' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('client_zonesoft_machines')
                    ->ignore($machine?->id)
                    ->where(fn ($query) => $query
                        ->where('client_id', $request->integer('client_id'))
                        ->where('zonesoft_application_id', $application->id)
                        ->where('zs_client_id', $request->string('zs_client_id')->toString())),
            ],
            'store_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     */
    private function validateMachines(
        Collection $machines,
        ZoneSoftDiscoveryService $discoveryService,
        string $emptyMessage,
    ): JsonResponse {
        $machines->loadMissing('application');
        $machinesByClientId = $machines->groupBy(
            fn (ClientZoneSoftMachine $machine): string => $machine->zonesoft_application_id.'|'.$machine->zs_client_id,
        );

        abort_if($machinesByClientId->isEmpty(), 422, $emptyMessage);
        $validatedAt = now();
        $validatedCount = 0;
        $failedCount = 0;
        $errors = [];
        $processedGroups = 0;

        foreach ($machinesByClientId as $clientMachines) {
            $application = $clientMachines->first()?->application;
            $zsClientId = (string) $clientMachines->first()?->zs_client_id;

            try {
                if (! $application || ! $application->is_active || ! $application->hasReadableSecret()) {
                    throw new \RuntimeException('A aplicação ZoneSoft deste Client ID não está disponível.');
                }

                $stores = collect(
                    $discoveryService->discoverStores($application, (string) $zsClientId),
                )->keyBy('id');

                foreach ($clientMachines as $machine) {
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
                $message = $exception instanceof ZoneSoftApiException && $exception->isRateLimited()
                    ? 'A ZoneSoft limitou temporariamente os pedidos deste Client ID. Tente novamente dentro de alguns instantes.'
                    : ($exception->getMessage() ?: 'Nao foi possivel validar as lojas deste Client ID.');

                foreach ($clientMachines as $machine) {
                    $machine->update([
                        'last_validated_at' => $validatedAt,
                        'last_error' => $message,
                    ]);
                    $failedCount++;
                }

                $errors[] = sprintf('Client ID %s: %s', $zsClientId, $message);
            }

            $processedGroups++;

            if ($processedGroups < $machinesByClientId->count()) {
                usleep(self::VALIDATE_ALL_DISCOVERY_PAUSE_MICROSECONDS);
            }
        }

        return response()->json([
            'message' => $failedCount === 0
                ? sprintf('%d loja(s) validadas com sucesso.', $validatedCount)
                : sprintf('%d loja(s) validadas e %d com erro.', $validatedCount, $failedCount),
            'validated' => $validatedCount,
            'failed' => $failedCount,
            'errors' => array_values(array_unique($errors)),
        ]);
    }

    private function getReadableApplication(?int $applicationId = null): ZoneSoftApplication
    {
        $application = ZoneSoftApplication::query()
            ->where('is_active', true)
            ->when($applicationId, fn ($query) => $query->whereKey($applicationId))
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveImportApplication(array $payload): ZoneSoftApplication
    {
        $externalId = trim((string) data_get($payload, 'application.id'));
        $name = trim((string) data_get($payload, 'application.name'));
        $application = ZoneSoftApplication::query()
            ->where('is_active', true)
            ->where(function ($query) use ($externalId, $name): void {
                $query->where('external_id', $externalId)
                    ->orWhere('name', $name);
            })
            ->first();

        if (! $application) {
            throw ValidationException::withMessages([
                'payload.application' => sprintf(
                    'Configure primeiro a aplicação ZoneSoft "%s" (ID %s) sem substituir as aplicações existentes.',
                    $name,
                    $externalId,
                ),
            ]);
        }

        if (! $application->hasReadableSecret()) {
            throw ValidationException::withMessages([
                'payload.application' => sprintf(
                    'O APP-SECRET da aplicação "%s" precisa de ser configurado novamente.',
                    $application->name,
                ),
            ]);
        }

        return $application;
    }
}
