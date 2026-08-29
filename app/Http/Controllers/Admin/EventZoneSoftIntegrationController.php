<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEventReportJob;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\ZoneSoftApplication;
use App\Services\EventReportSyncService;
use App\Services\ZoneSoft\ZoneSoftApiClient;
use App\Services\ZoneSoft\ZoneSoftApiException;
use App\Services\ZoneSoft\ZoneSoftDiscoveryService;
use Carbon\CarbonImmutable;
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

class EventZoneSoftIntegrationController extends Controller
{
    private const DEFAULT_MACHINE_PERMISSIONS = 'API + All document interfaces';

    private const VALIDATE_ALL_DISCOVERY_PAUSE_MICROSECONDS = 500000;

    public function show(Event $event): Response
    {
        return $this->renderTpaManager($event);
    }

    public function manageTpas(Event $event): Response
    {
        return $this->renderTpaManager($event);
    }

    public function syncMachines(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'machine_ids' => ['present', 'array', 'max:500'],
            'machine_ids.*' => ['integer', 'distinct', 'exists:client_zonesoft_machines,id'],
        ]);
        $machineIds = collect($validated['machine_ids'])->map(fn ($id): int => (int) $id)->values();
        $validMachineIds = ClientZoneSoftMachine::query()
            ->where('client_id', $event->client_id)
            ->whereIn('id', $machineIds)
            ->pluck('id');

        if ($validMachineIds->count() !== $machineIds->count()) {
            throw ValidationException::withMessages([
                'machine_ids' => 'Só pode associar TPAs globais pertencentes ao cliente deste evento.',
            ]);
        }

        $selectedLicenses = ClientZoneSoftMachine::query()
            ->whereIn('id', $validMachineIds)
            ->pluck('license')
            ->filter(fn (?string $license): bool => filled($license))
            ->unique();

        if ($selectedLicenses->count() > 1) {
            throw ValidationException::withMessages([
                'machine_ids' => 'Todos os TPAs do evento devem usar a mesma licença ZoneSoft.',
            ]);
        }

        $selectedApplications = ClientZoneSoftMachine::query()
            ->whereIn('id', $validMachineIds)
            ->pluck('zonesoft_application_id')
            ->unique();

        if ($selectedApplications->count() > 1) {
            throw ValidationException::withMessages([
                'machine_ids' => 'Todos os TPAs do evento devem usar a mesma aplicação ZoneSoft.',
            ]);
        }

        $event->zonesoftMachines()->sync($validMachineIds->all());

        return to_route('admin.events.tpas.manage', $event);
    }

    public function sessionStatus(
        Event $event,
        ClientZoneSoftMachine $machine,
        ZoneSoftApiClient $apiClient,
    ): JsonResponse {
        $machine = $this->resolveEventMachine($event, $machine);
        $machine->loadMissing('application');

        if (! $machine->application || ! $machine->application->is_active || ! $machine->application->hasReadableSecret()) {
            return response()->json([
                'status' => 'unknown',
                'label' => 'Indisponível',
                'message' => 'A aplicação ZoneSoft deste TPA não está disponível.',
                'session' => null,
            ], 422);
        }

        try {
            $response = $apiClient->post(
                $machine->application,
                $machine->zs_client_id,
                'salessessions',
                'getOpenSaleSessionInstance',
                'salessession',
                [
                    'caixa' => $machine->store_id,
                    'data' => $event->event_date->toDateString(),
                ],
                requestTimeoutSeconds: 12,
                requestRetryAttempts: 1,
            );
        } catch (ZoneSoftApiException $exception) {
            return response()->json([
                'status' => 'unknown',
                'label' => 'Indisponível',
                'message' => $exception->getMessage(),
                'session' => null,
            ], $exception->statusCode() ?: 422);
        }

        $session = $this->extractSaleSession($response);

        if ($session === null) {
            return response()->json([
                'status' => 'closed',
                'label' => 'Fechada',
                'message' => 'Nenhuma sessão aberta encontrada para este TPA na data do evento.',
                'session' => null,
            ]);
        }

        $isOpen = $this->isOpenSaleSession($session);

        return response()->json([
            'status' => $isOpen ? 'open' : 'closed',
            'label' => $isOpen ? 'Aberta' : 'Fechada',
            'message' => $isOpen
                ? 'Existe uma sessão aberta para este TPA.'
                : 'A última sessão encontrada para este TPA já está fechada.',
            'session' => [
                'cash_register' => (int) ($session['caixa'] ?? $machine->store_id),
                'opened_at' => $this->toIsoString($session['dataopen'] ?? null),
                'closed_at' => $this->toIsoString($session['dataclose'] ?? null),
                'opened_by' => $session['opencx'] ?? null,
                'closed_by' => $session['closecx'] ?? null,
                'session_id' => $session['idcx'] ?? null,
                'employee_id' => $session['empid'] ?? null,
            ],
        ]);
    }

    public function syncSales(
        Request $request,
        Event $event,
        ClientZoneSoftMachine $machine,
        EventReportSyncService $syncService,
    ): JsonResponse {
        $machine = $this->resolveEventMachine($event, $machine);
        $validated = $request->validate([
            'redirect_to' => ['nullable', 'string', 'max:2048'],
        ]);

        if (app()->runningUnitTests() || app()->isLocal()) {
            $syncService->sync($event, $request->user());

            return response()->json([
                'message' => sprintf(
                    'Sincronização das vendas iniciada para o evento a partir do TPA %s.',
                    $machine->store_label ?: 'Store '.$machine->store_id,
                ),
                'redirect_to' => $validated['redirect_to'] ?? null,
            ]);
        }

        $syncLog = $syncService->start($event, $request->user());
        SyncEventReportJob::dispatch($syncLog->id, $event->id);

        return response()->json([
            'message' => sprintf(
                'Sincronização das vendas iniciada para o evento a partir do TPA %s.',
                $machine->store_label ?: 'Store '.$machine->store_id,
            ),
            'redirect_to' => $validated['redirect_to'] ?? null,
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
            'store_id' => ['required', 'integer', 'min:0'],
            'store_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $machine = ClientZoneSoftMachine::query()->firstOrNew([
            'client_id' => $event->client_id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => $validated['zs_client_id'],
            'store_id' => $validated['store_id'],
        ]);
        $machine->fill([
            ...$validated,
            'permissions' => self::DEFAULT_MACHINE_PERMISSIONS,
            'last_validated_at' => now(),
            'last_error' => null,
        ]);
        $machine->save();
        $event->zonesoftMachines()->syncWithoutDetaching([$machine->id]);

        return to_route('admin.events.integrations.show', $event);
    }

    public function updateMachine(
        Request $request,
        Event $event,
        ClientZoneSoftMachine $machine,
    ): RedirectResponse {
        abort_unless(
            $machine->client_id === $event->client_id
            && $event->zonesoftMachines()->whereKey($machine->id)->exists(),
            404,
        );

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
                            ->where('client_id', $event->client_id)
                            ->where('zonesoft_application_id', $machine->zonesoft_application_id)
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
        abort_unless(
            $machine->client_id === $event->client_id
            && $event->zonesoftMachines()->whereKey($machine->id)->exists(),
            404,
        );

        $event->zonesoftMachines()->detach($machine->id);

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
        $machines->loadMissing('application');
        $machinesByClientId = $machines->groupBy(
            fn (ClientZoneSoftMachine $machine): string => $machine->zonesoft_application_id.'|'.$machine->zs_client_id,
        );

        abort_if($machinesByClientId->isEmpty(), 422, $emptyMessage);
        $validatedAt = now();
        $validatedCount = 0;
        $failedCount = 0;
        $errors = [];
        $clientIdGroupsCount = $machinesByClientId->count();
        $processedGroups = 0;

        foreach ($machinesByClientId as $machines) {
            $application = $machines->first()?->application;
            $zsClientId = (string) $machines->first()?->zs_client_id;

            try {
                if (! $application || ! $application->is_active || ! $application->hasReadableSecret()) {
                    throw new \RuntimeException('A aplicação ZoneSoft deste Client ID não está disponível.');
                }

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

    private function resolveEventMachine(Event $event, ClientZoneSoftMachine $machine): ClientZoneSoftMachine
    {
        abort_unless(
            $machine->client_id === $event->client_id
            && $event->zonesoftMachines()->whereKey($machine->id)->exists(),
            404,
        );

        return $machine;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractSaleSession(array $payload): ?array
    {
        $session = $payload['salessession'] ?? $payload['saleSession'] ?? $payload['session'] ?? null;

        if (is_array($session) && array_is_list($session)) {
            $session = $session[0] ?? null;
        }

        if (is_array($session)) {
            return $session;
        }

        if (array_is_list($payload)) {
            $first = $payload[0] ?? null;

            return is_array($first) ? $first : null;
        }

        return isset($payload['caixa']) || isset($payload['dataopen']) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isOpenSaleSession(array $session): bool
    {
        if (array_key_exists('status', $session) && is_numeric($session['status'])) {
            return (int) $session['status'] === 1;
        }

        if (array_key_exists('dataclose', $session)) {
            $closedAt = $session['dataclose'];

            return ! is_string($closedAt) || trim($closedAt) === '';
        }

        return true;
    }

    private function toIsoString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function renderTpaManager(Event $event): Response
    {
        $event->load('client');
        $selectedMachineIds = $event->zonesoftMachines()->pluck('client_zonesoft_machines.id');

        return Inertia::render('Admin/Events/ManageTpas', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date->toISOString(),
            ],
            'client' => [
                'id' => $event->client->id,
                'name' => $event->client->name,
            ],
            'machines' => $event->client->zonesoftMachines()
                ->orderBy('license')
                ->orderBy('store_id')
                ->get()
                ->map(fn (ClientZoneSoftMachine $machine): array => [
                    'id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'store_id' => $machine->store_id,
                    'store_label' => $machine->store_label,
                    'license' => $machine->license,
                    'permissions' => $machine->permissions,
                    'is_active' => $machine->is_active,
                    'last_validated_at' => $machine->last_validated_at?->toISOString(),
                    'last_error' => $machine->last_error,
                    'is_selected' => $selectedMachineIds->contains($machine->id),
                ])
                ->values(),
        ]);
    }
}
