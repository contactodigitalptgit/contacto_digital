<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncEventReportJob;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\EventReportSyncService;
use App\Services\ZoneSoft\ZoneSoftApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventReportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['event-reports.zonesoft.complete_documents' => false]);
    }

    public function test_admin_can_save_global_zonesoft_integration_and_assign_it_to_an_event(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);

        $this
            ->actingAs($admin)
            ->post(route('admin.integrations.zonesoft.application.save'), [
                'name' => 'ZoneSoft Principal',
                'base_url' => 'https://api.zonesoft.org/v3',
                'app_key' => 'app-key-123',
                'app_secret' => 'secret-123',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.integrations.zonesoft.index'));

        $application = ZoneSoftApplication::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('admin.integrations.zonesoft.machines.store'), [
                'client_id' => $client->id,
                'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
                'license' => 'Z11JSMZIYP',
                'store_id' => 1,
                'store_label' => 'Loja 1 (PT)',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.integrations.zonesoft.index'));

        $machine = ClientZoneSoftMachine::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->put(route('admin.events.tpas.sync', $event), [
                'machine_ids' => [$machine->id],
            ])
            ->assertRedirect(route('admin.events.tpas.manage', $event));

        $this->assertDatabaseHas('zonesoft_applications', [
            'id' => $application->id,
            'name' => 'ZoneSoft Principal',
            'app_key' => 'app-key-123',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1 (PT)',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('event_zonesoft_machines', [
            'event_id' => $event->id,
            'client_zonesoft_machine_id' => $machine->id,
        ]);
    }

    public function test_event_rejects_tpas_from_different_zonesoft_licenses(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);
        $firstMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'LICENSE-A-CLIENT',
            'license' => 'LICENSE-A',
            'store_id' => 1,
            'is_active' => true,
        ]);
        $secondMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'LICENSE-B-CLIENT',
            'license' => 'LICENSE-B',
            'store_id' => 2,
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->put(route('admin.events.tpas.sync', $event), [
                'machine_ids' => [$firstMachine->id, $secondMachine->id],
            ])
            ->assertSessionHasErrors(['machine_ids']);

        $this->assertDatabaseMissing('event_zonesoft_machines', [
            'event_id' => $event->id,
        ]);
    }

    public function test_events_can_reuse_the_same_global_zonesoft_integration(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $firstEvent = Event::create([
            'client_id' => $client->id,
            'title' => 'Primeiro evento',
            'event_date' => now()->addDay(),
            'report_ends_at' => now()->addDays(2),
            'is_active' => true,
        ]);
        $secondEvent = Event::create([
            'client_id' => $client->id,
            'title' => 'Segundo evento',
            'event_date' => now()->addDays(3),
            'report_ends_at' => now()->addDays(4),
            'is_active' => true,
        ]);

        foreach ([$firstEvent, $secondEvent] as $event) {
            $this
                ->actingAs($admin)
                ->post(route('admin.events.integrations.machines.store', $event), [
                    'zs_client_id' => 'SAME-CLIENT-ID',
                    'license' => 'EVENT-LICENSE',
                    'store_id' => 1,
                    'store_label' => 'Máquina '.$event->id,
                    'is_active' => true,
                ])
                ->assertRedirect(route('admin.events.integrations.show', $event));
        }

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SAME-CLIENT-ID',
            'store_id' => 1,
        ]);
        $this->assertDatabaseCount('client_zonesoft_machines', 1);
        $this->assertSame(1, $firstEvent->zonesoftMachines()->count());
        $this->assertSame(1, $secondEvent->zonesoftMachines()->count());
        $firstMachine = $firstEvent->zonesoftMachines()->firstOrFail();
        $secondMachine = $secondEvent->zonesoftMachines()->firstOrFail();
        $this->assertSame($firstMachine->id, $secondMachine->id);

        $this
            ->actingAs($admin)
            ->delete(route('admin.events.integrations.machines.destroy', [
                $secondEvent,
                $firstMachine,
            ]))
            ->assertRedirect(route('admin.events.integrations.show', $secondEvent));

        $this->assertSame(1, $firstEvent->zonesoftMachines()->count());
        $this->assertSame(0, $secondEvent->zonesoftMachines()->count());
        $this->assertDatabaseHas('client_zonesoft_machines', ['id' => $firstMachine->id]);

        $this
            ->actingAs($admin)
            ->get(route('admin.events.integrations.show', $firstEvent))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/ManageTpas')
                ->where('event.id', $firstEvent->id)
                ->where('event.title', 'Primeiro evento')
                ->where('client.id', $client->id)
                ->has('machines', 1)
                ->where('machines.0.is_selected', true));
    }

    public function test_admin_can_list_global_tpas_and_see_which_are_linked_to_an_event(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);
        $otherEvent = $this->makeEvent($client);

        $event->zonesoftMachines()->create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'EVENT-TPA-CLIENT',
            'license' => 'EVENT-TPA-LICENSE',
            'store_id' => 15,
            'store_label' => 'Bar Principal - POS 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);
        $otherEvent->zonesoftMachines()->create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'OTHER-TPA-CLIENT',
            'license' => 'OTHER-TPA-LICENSE',
            'store_id' => 16,
            'store_label' => 'Outro evento - POS 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.events.tpas.manage', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/ManageTpas')
                ->where('event.id', $event->id)
                ->where('event.title', $event->title)
                ->has('machines', 2)
                ->where('machines.0.store_id', 15)
                ->where('machines.0.store_label', 'Bar Principal - POS 1')
                ->where('machines.0.is_selected', true)
                ->where('machines.1.store_id', 16)
                ->where('machines.1.is_selected', false));
    }

    public function test_admin_can_discover_stores_for_client_id(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $this->makeApplication();

        Http::fake([
            'https://api.zonesoft.org/v3/stores/getInstances' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'store' => [
                            ['codigo' => 0, 'designacao' => 'Loja 0', 'pais' => 'PT'],
                            ['codigo' => 30, 'descricao' => 'Loja 30', 'pais' => 'PT'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.events.integrations.discover-stores', $event), [
                'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            ]);

        $response->assertOk();
        $response->assertJson([
            'stores' => [
                ['id' => 0, 'label' => 'Loja 0', 'country' => 'PT'],
                ['id' => 30, 'label' => 'Loja 30', 'country' => 'PT'],
            ],
        ]);
    }

    public function test_integrations_page_handles_imported_invalid_secret_without_crashing(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();

        DB::table('zonesoft_applications')
            ->where('id', $application->id)
            ->update([
                'app_secret' => 'invalid-imported-secret',
                'updated_at' => now(),
            ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.integrations.zonesoft.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Integrations/ZoneSoft')
                ->where('application.has_secret', true)
                ->where('application.has_usable_secret', false)
                ->where('application.requires_secret_reconfiguration', true));

        $this
            ->actingAs($admin)
            ->from(route('admin.integrations.zonesoft.index'))
            ->post(route('admin.integrations.zonesoft.application.save'), [
                'name' => 'ZoneSoft Principal',
                'base_url' => 'https://api.zonesoft.org/v3',
                'app_key' => 'app-key-123',
                'app_secret' => '',
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['app_secret']);
    }

    public function test_admin_can_replace_imported_invalid_secret_when_saving_application(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();

        DB::table('zonesoft_applications')
            ->where('id', $application->id)
            ->update([
                'app_secret' => 'invalid-imported-secret',
                'updated_at' => now(),
            ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.integrations.application.save', $event), [
                'name' => 'ZoneSoft Principal',
                'base_url' => 'https://api.zonesoft.org/v3',
                'app_key' => 'app-key-456',
                'app_secret' => 'secret-replaced',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.events.integrations.show', $event));

        $reloaded = ZoneSoftApplication::query()->findOrFail($application->id);

        $this->assertSame('app-key-456', $reloaded->app_key);
        $this->assertTrue($reloaded->hasReadableSecret());
        $this->assertFalse($reloaded->requiresSecretReconfiguration());
        $this->assertSame('secret-replaced', $reloaded->app_secret);
    }

    public function test_admin_can_validate_all_registered_machine_stores_at_once(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();

        $machineZero = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 0,
            'store_label' => null,
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        $machineThirty = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 30,
            'store_label' => null,
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        $missingMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 99,
            'store_label' => 'Loja Antiga',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/stores/getInstances' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'store' => [
                            ['codigo' => 0, 'designacao' => 'Loja 0', 'pais' => 'PT'],
                            ['codigo' => 30, 'descricao' => 'Loja 30', 'pais' => 'PT'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.events.integrations.machines.validate-all', $event));

        $response->assertOk();
        $response->assertJson([
            'validated' => 2,
            'failed' => 1,
            'message' => '2 loja(s) validadas e 1 com erro.',
        ]);

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'id' => $machineZero->id,
            'store_label' => 'Loja 0',
            'last_error' => null,
        ]);

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'id' => $machineThirty->id,
            'store_label' => 'Loja 30',
            'last_error' => null,
        ]);

        $missingMachine->refresh();

        $this->assertSame('Loja Antiga', $missingMachine->store_label);
        $this->assertNotNull($missingMachine->last_validated_at);
        $this->assertStringContainsString('Store ID 99', $missingMachine->last_error ?? '');
        Http::assertSentCount(1);
    }

    public function test_admin_can_validate_all_registered_machine_stores_after_rate_limit_retry(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();

        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => '90E0DBFF6ED8FC9A5895758357A97A1C',
            'license' => 'Z11JSMZIYP',
            'store_id' => 115,
            'store_label' => null,
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/stores/getInstances' => Http::sequence()
                ->push('You have exceeded the rate limit. Please try again later.', 429)
                ->push([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'store' => [
                                ['codigo' => 115, 'designacao' => 'Bar 2 Leonor', 'pais' => 'PT'],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.events.integrations.machines.validate-all', $event));

        $response->assertOk();
        $response->assertJson([
            'validated' => 1,
            'failed' => 0,
            'message' => '1 loja(s) validadas com sucesso.',
        ]);

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'id' => $machine->id,
            'store_label' => 'Bar 2 Leonor',
            'last_error' => null,
        ]);

        Http::assertSentCount(2);
    }

    public function test_admin_can_check_event_tpa_session_status(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();
        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SESSION-STATUS-CLIENT',
            'license' => 'SESSION-LICENSE',
            'store_id' => 151,
            'store_label' => 'Bar Central',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/salessessions/getOpenSaleSessionInstance' => function ($request) {
                $this->assertSame(151, $request->data()['salessession']['caixa'] ?? null);
                $this->assertSame('2026-06-20', $request->data()['salessession']['data'] ?? null);

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'salessession' => [[
                                'caixa' => 151,
                                'dataopen' => '2026-06-20 11:30:00',
                                'dataclose' => null,
                                'opencx' => 'Operador API',
                                'closecx' => null,
                                'idcx' => 3456,
                                'empid' => 22,
                            ]],
                        ],
                    ],
                ]);
            },
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.tpas.session-status', [$event, $machine]))
            ->assertOk()
            ->assertJson([
                'status' => 'open',
                'label' => 'Aberta',
                'message' => 'Existe uma sessão aberta para este TPA.',
                'session' => [
                    'cash_register' => 151,
                    'opened_by' => 'Operador API',
                    'session_id' => 3456,
                    'employee_id' => 22,
                ],
            ]);
    }

    public function test_admin_marks_closed_session_as_closed_when_zonesoft_returns_a_closed_record(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();
        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SESSION-CLOSED-CLIENT',
            'license' => 'SESSION-LICENSE',
            'store_id' => 163,
            'store_label' => 'Bar Teste',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/salessessions/getOpenSaleSessionInstance' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'salessession' => [
                            'caixa' => 163,
                            'dataopen' => '2026-08-29 23:14:40',
                            'dataclose' => '2026-08-29 23:15:08',
                            'opencx' => '10',
                            'closecx' => '10',
                            'idcx' => 23,
                            'empid' => 10,
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.tpas.session-status', [$event, $machine]))
            ->assertOk()
            ->assertJson([
                'status' => 'closed',
                'label' => 'Fechada',
                'message' => 'A última sessão encontrada para este TPA já está fechada.',
                'session' => [
                    'cash_register' => 163,
                    'opened_by' => '10',
                    'closed_by' => '10',
                    'session_id' => 23,
                    'employee_id' => 10,
                ],
            ]);
    }

    public function test_admin_can_start_sales_sync_from_event_tpa_panel(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $application = $this->makeApplication();
        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SYNC-TPA-CLIENT',
            'license' => 'SYNC-LICENSE',
            'store_id' => 152,
            'store_label' => 'Bar Expo',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);

        $this->mock(EventReportSyncService::class, function ($mock) use ($event, $admin): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(fn (Event $receivedEvent, User $receivedUser): bool => $receivedEvent->is($event) && $receivedUser->is($admin))
                ->andReturn(new EventReportImport([
                    'event_id' => $event->id,
                    'uploaded_by_user_id' => $admin->id,
                    'status' => 'completed',
                ]));
        });

        $this
            ->actingAs($admin)
            ->post(route('admin.events.tpas.sync-sales', [$event, $machine]), [
                'redirect_to' => route('admin.events.tpas.manage', $event, absolute: false),
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Sincronização das vendas iniciada para o evento a partir do TPA Bar Expo.',
                'redirect_to' => route('admin.events.tpas.manage', $event, absolute: false),
            ]);
    }

    public function test_admin_can_sync_event_report_from_zonesoft_api(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) {
                $this->assertSame('app-key-123', $request->header('X-ZS-APP-KEY')[0] ?? null);
                $this->assertSame('B3FC7C254EBDD7505C9CFA30468213B0', $request->header('X-ZS-CLIENT-ID')[0] ?? null);
                $this->assertNotEmpty($request->header('X-ZS-SIGNATURE')[0] ?? null);
                $this->assertSame(
                    "loja = 1 and data >= '2026-06-20' and data <= '2026-06-20'",
                    $request->data()['document']['condition'] ?? null,
                );

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [
                                [
                                    'numero' => 501,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => '2026-06-20',
                                    'datahora' => '2026-06-20 12:05:00',
                                    'pagamento' => 3,
                                    'total' => 8.7,
                                    'pago' => 1,
                                    'documentos_pagamento' => [
                                        [
                                            'doc' => 'PG',
                                            'serie' => 'A2026',
                                            'numero' => 1,
                                            'tipo' => 1,
                                            'valor' => 2.0,
                                        ],
                                        [
                                            'doc' => 'PG',
                                            'serie' => 'A2026',
                                            'numero' => 2,
                                            'tipo' => 3,
                                            'valor' => 6.7,
                                        ],
                                    ],
                                ],
                                [
                                    'numero' => 502,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => '2026-06-20',
                                    'datahora' => '2026-06-20 12:10:00',
                                    'pagamento' => 1,
                                    'total' => 25,
                                    'pago' => 1,
                                    'anulado' => 1,
                                    'empanulado' => 7,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'sale' => [
                            [
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:00:00',
                                'codigo' => 730,
                                'descricao' => 'Agua',
                                'qtd' => 2,
                                'valor' => 4.8673,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 5.5,
                                'posto' => 1,
                            ],
                            [
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:05:00',
                                'codigo' => 730,
                                'descricao' => 'Cerveja',
                                'qtd' => 1,
                                'valor' => 2.92,
                                'desconto' => 0.2,
                                'desconto2' => 0,
                                'total' => 3.2,
                                'posto' => 1,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/salesday/getInstances' => function ($request) {
                $this->assertSame(
                    "loja = 1 and data >= '2026-06-20' and data <= '2026-06-20'",
                    $request->data()['salesday']['condition'] ?? null,
                );

                return $this->fakeSalesdayResponse([
                    [
                        'loja' => 1,
                        'data' => '2026-06-20',
                        'caixa' => 1,
                        'Open' => 1,
                        'fs' => 5.50,
                        'ft' => 3.20,
                        'movimento' => 8.70,
                        'num' => 5.50,
                        'deb' => 3.20,
                    ],
                ]);
            },
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event), [
                'redirect_to' => route('admin.events.dashboard', $event, absolute: false),
            ])
            ->assertRedirect(route('admin.events.dashboard', $event));

        $import = EventReportImport::query()->firstOrFail();

        $this->assertSame('zonesoft-api', $import->original_filename);
        $this->assertTrue($import->is_active);
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported_rows_count);
        $this->assertSame('zonesoft_api', $import->summary['source'] ?? null);
        $this->assertSame(1, $import->summary['machines_count'] ?? null);
        $this->assertSame(2, $import->summary['payment_documents_count'] ?? null);
        $this->assertArrayNotHasKey('payment_documents', $import->summary);
        $paymentDocuments = $import->paymentDocuments()->orderBy('id')->get();
        $this->assertCount(2, $paymentDocuments);
        $this->assertSame('1', $paymentDocuments[0]->payment_code);
        $this->assertSame('2.0000', $paymentDocuments[0]->total);
        $this->assertSame('3', $paymentDocuments[1]->payment_code);
        $this->assertSame('6.7000', $paymentDocuments[1]->total);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.zonesoft.org/v3/salesday/getInstances');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.zonesoft.org/v3/sales/getInstancesFromDocument'
            && ($request->data()['sale']['numero'] ?? null) === 502);

        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'product_code' => '730',
            'description' => 'Agua',
            'store_name' => 'Loja 1 - POS 1',
        ]);
    }

    public function test_sync_uses_complete_documents_without_per_document_sales_requests(): void
    {
        config(['event-reports.zonesoft.complete_documents' => true]);

        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'COMPLETE-DOCUMENTS-CLIENT',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja completa',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getInstances' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'document' => [
                            [
                                'numero' => 601,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:05:00',
                                'pagamento' => 9,
                                'total' => 8.70,
                                'pago' => 1,
                                'documentos_pagamento' => [
                                    ['doc' => 'PG', 'serie' => 'A2026', 'numero' => 1, 'tipo' => 1, 'valor' => 2.00],
                                    ['doc' => 'PG', 'serie' => 'A2026', 'numero' => 2, 'tipo' => 3, 'valor' => 5.20],
                                ],
                                'vendas' => [
                                    [
                                        'loja' => 1,
                                        'numero' => 601,
                                        'doc' => 'FS',
                                        'serie' => 'A2026',
                                        'data' => '2026-06-20',
                                        'datahora' => '2026-06-20 12:05:00',
                                        'codigo' => 730,
                                        'descricao' => 'Agua',
                                        'qtd' => 1,
                                        'valor' => 7.0732,
                                        'desconto' => 0,
                                        'desconto2' => 0,
                                        'total' => 8.70,
                                        'posto' => 1,
                                        'pvp' => 99.99,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->imported_rows_count);
        $this->assertSame(1, $import->summary['api_requests_count'] ?? null);
        $this->assertSame(1, $import->summary['documents_processed'] ?? null);
        $this->assertSame(3, $import->summary['payment_documents_count'] ?? null);
        $this->assertArrayNotHasKey('payment_documents', $import->summary);
        $paymentDocuments = $import->paymentDocuments()->orderBy('id')->get();
        $this->assertCount(3, $paymentDocuments);
        $this->assertSame('2.0000', $paymentDocuments[0]->total);
        $this->assertSame('5.2000', $paymentDocuments[1]->total);
        $this->assertSame('1.5000', $paymentDocuments[2]->total);
        $this->assertTrue($paymentDocuments[2]->is_unallocated);
        $this->assertDatabaseHas('event_report_rows', [
            'event_report_import_id' => $import->id,
            'document_number' => '601',
            'description' => 'Agua',
            'total' => 8.7,
        ]);
        $storedRow = $import->rows()->firstOrFail();
        $this->assertSame([
            'machine_id' => $storedRow->raw_row['machine_id'],
            'machine_client_id' => 'COMPLETE-DOCUMENTS-CLIENT',
            'machine_store_id' => 1,
            'id' => null,
            '_document_line_number' => 1,
        ], $storedRow->raw_row);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sales/getInstancesFromDocument'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/documents/getDocumentsHeaders'));
    }

    public function test_incremental_complete_document_sync_replaces_changed_and_cancelled_documents(): void
    {
        config([
            'event-reports.zonesoft.complete_documents' => true,
            'event-reports.zonesoft.incremental_overlap_minutes' => 15,
        ]);
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-14 12:00:00', 'Europe/Lisbon'),
        );

        try {
            [$admin, $client] = $this->makeAdminClientContext();
            $application = $this->makeApplication();
            $event = Event::create([
                'client_id' => $client->id,
                'title' => 'Evento incremental',
                'event_date' => '2026-08-10 12:00:00',
                'report_starts_at' => '2026-08-10 00:00:00',
                'report_ends_at' => '2026-08-15 23:59:59',
                'is_active' => true,
            ]);
            $machine = ClientZoneSoftMachine::create([
                'client_id' => $client->id,
                'event_id' => $event->id,
                'zonesoft_application_id' => $application->id,
                'zs_client_id' => 'INCREMENTAL-COMPLETE-CLIENT',
                'license' => 'Z11JSMZIYP',
                'store_id' => 1,
                'store_label' => 'Loja incremental',
                'permissions' => 'API + All document interfaces',
                'is_active' => true,
                'last_validated_at' => now(),
            ]);
            $previousImport = EventReportImport::create([
                'event_id' => $event->id,
                'uploaded_by_user_id' => $admin->id,
                'import_strategy' => 'replace',
                'original_filename' => 'zonesoft-api',
                'stored_path' => 'zonesoft://sync',
                'mime_type' => 'application/json',
                'file_hash' => hash('sha256', 'verified-incremental-snapshot'),
                'headers' => [
                    'source' => 'zonesoft_api',
                    'machines' => [['id' => $machine->id]],
                ],
                'summary' => [
                    'source' => 'zonesoft_api',
                    'machines_count' => 1,
                    'failed_machines' => [],
                    'machine_warnings' => [],
                    'historical_data_complete' => true,
                    'document_cursor_version' => 1,
                    'last_full_document_sync_at' => '2026-08-14T11:00:00+01:00',
                    'machine_document_cursors' => [
                        (string) $machine->id => [
                            'machine_id' => $machine->id,
                            'zs_client_id' => $machine->zs_client_id,
                            'store_id' => 1,
                            'cursor' => '2026-08-14T11:45:00+01:00',
                        ],
                    ],
                ],
                'imported_rows_count' => 3,
                'imported_at' => now()->subHour(),
                'is_active' => true,
                'status' => 'completed',
            ]);

            // event_report_rows/event_report_payment_documents are now the
            // durable, current state of the event (PERF-101) — these rows
            // represent what an earlier sync already wrote, matched by
            // natural key exactly like a real upsert would compute it, so
            // the incremental cycle below can update/reconcile them in
            // place instead of copying them.
            $unchangedRowId = null;

            foreach ([
                ['100', '5.0000', '700', 'Documento alterado'],
                ['101', '3.0000', '701', 'Documento inalterado'],
                ['103', '2.0000', '703', 'Documento cancelado'],
            ] as $index => [$documentNumber, $total, $productCode, $description]) {
                $row = EventReportRow::create([
                    'event_id' => $event->id,
                    'event_report_import_id' => $previousImport->id,
                    'machine_id' => $machine->id,
                    'source_sheet' => 'zonesoft:'.$machine->zs_client_id,
                    'source_row_number' => $index + 1,
                    'store_code' => '1',
                    'store_name' => 'Loja incremental',
                    'sale_date' => '2026-08-13',
                    'sale_datetime' => '2026-08-13 20:00:00',
                    'doc_type' => 'FS',
                    'document_series' => 'A2026',
                    'document_number' => $documentNumber,
                    'line_key' => 'id:'.$documentNumber,
                    'value' => $total,
                    'total' => $total,
                    'discount' => '0.0000',
                    'quantity' => '1.0000',
                    'product_code' => $productCode,
                    'description' => $description,
                    'raw_row' => [
                        'machine_id' => $machine->id,
                        'machine_client_id' => $machine->zs_client_id,
                        'id' => (int) $documentNumber,
                    ],
                ]);

                if ($documentNumber === '101') {
                    $unchangedRowId = $row->id;
                }

                EventReportPaymentDocument::create([
                    'event_id' => $event->id,
                    'event_report_import_id' => $previousImport->id,
                    'machine_id' => $machine->id,
                    'machine_client_id' => $machine->zs_client_id,
                    'store_code' => '1',
                    'store_name' => 'Loja incremental',
                    'sale_date' => '2026-08-13',
                    'sale_datetime' => '2026-08-13 20:00:00',
                    'doc_type' => 'FS',
                    'document_series' => 'A2026',
                    'document_number' => $documentNumber,
                    'paid' => true,
                    'document_total' => $total,
                    'payment_key' => 'header',
                    'payment_code' => '3',
                    'total' => $total,
                    'is_unallocated' => false,
                    'dedupe_key' => hash('sha256', implode('|', [
                        $machine->zs_client_id, '1', 'FS', 'A2026', $documentNumber, 'header', '3',
                    ])),
                ]);
            }

            $unchangedRowUpdatedAt = EventReportRow::query()->findOrFail($unchangedRowId)->updated_at;

            $expectFullRefresh = false;
            Http::fake([
                'https://api.zonesoft.org/v3/documents/getInstances' => function ($request) use (&$expectFullRefresh) {
                    $this->assertSame(
                        $expectFullRefresh
                            ? "loja = 1 and data >= '2026-08-10' and data <= '2026-08-15'"
                            : "loja = 1 and data >= '2026-08-10' and data <= '2026-08-15' and lastupdate >= '2026-08-14 11:30:00'",
                        $request->data()['document']['condition'] ?? null,
                    );

                    $documents = $expectFullRefresh
                        ? [
                            $this->completeDocumentFixture(100, 7, 700, 'Documento atualizado'),
                            $this->completeDocumentFixture(101, 3, 701, 'Documento inalterado'),
                            $this->completeDocumentFixture(102, 4, 702, 'Documento novo'),
                        ]
                        : [
                            $this->completeDocumentFixture(100, 7, 700, 'Documento atualizado'),
                            $this->completeDocumentFixture(102, 4, 702, 'Documento novo'),
                            [
                                'loja' => 1,
                                'numero' => 103,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'anulado' => 1,
                            ],
                        ];

                    return Http::response([
                        'Response' => [
                            'StatusCode' => 200,
                            'StatusMessage' => 'OK',
                            'Content' => [
                                'document' => $documents,
                            ],
                        ],
                    ]);
                },
            ]);

            $import = app(EventReportSyncService::class)->sync($event, $admin);

            $this->assertSame('completed', $import->status);
            $this->assertSame('incremental', $import->summary['document_fetch_mode'] ?? null);
            $this->assertSame(3, $import->imported_rows_count);
            $this->assertSame(1, $import->summary['reused_rows_count'] ?? null);
            $this->assertSame(2, $import->summary['fetched_rows_count'] ?? null);
            $this->assertSame('14.0000', $import->summary['sales_total'] ?? null);
            $this->assertSame(
                '2026-08-14T12:00:00+01:00',
                $import->summary['machine_document_cursors'][(string) $machine->id]['cursor'] ?? null,
            );
            $this->assertSame(
                '2026-08-14T11:30:00+01:00',
                $import->summary['machine_document_cursors'][(string) $machine->id]['requested_after'] ?? null,
            );
            $this->assertSame(
                '2026-08-14T11:00:00+01:00',
                $import->summary['last_full_document_sync_at'] ?? null,
            );
            // PERF-101: doc 100 changed (updated in place, same row id — the
            // whole point of the natural-key upsert), doc 102 is new, doc
            // 103 was cancelled (line reconciled away). event_report_import_id
            // now points at *this* import for every row it actually touched.
            $this->assertDatabaseHas('event_report_rows', [
                'event_report_import_id' => $import->id,
                'document_number' => '100',
                'description' => 'Documento atualizado',
                'total' => 7,
            ]);
            $this->assertDatabaseHas('event_report_rows', [
                'event_report_import_id' => $import->id,
                'document_number' => '102',
                'description' => 'Documento novo',
                'total' => 4,
            ]);
            $this->assertDatabaseMissing('event_report_rows', [
                'event_id' => $event->id,
                'document_number' => '103',
            ]);

            // doc 101 was never part of this incremental fetch (no
            // `lastupdate` change) — it must be left completely untouched:
            // same row id, same event_report_import_id, same updated_at.
            // This is the "zero writes for unchanged data" guarantee.
            $unchangedRow = EventReportRow::query()->findOrFail($unchangedRowId);
            $this->assertSame($previousImport->id, $unchangedRow->event_report_import_id);
            $this->assertSame('101', $unchangedRow->document_number);
            $this->assertSame('Documento inalterado', $unchangedRow->description);
            $this->assertTrue($unchangedRow->updated_at->equalTo($unchangedRowUpdatedAt));

            $this->assertSame(3, EventReportPaymentDocument::query()->where('event_id', $event->id)->count());
            $this->assertSame(14.0, (float) EventReportPaymentDocument::query()->where('event_id', $event->id)->sum('total'));
            $this->assertFalse($previousImport->fresh()->is_active);
            Http::assertSentCount(1);

            CarbonImmutable::setTestNow(
                CarbonImmutable::parse('2026-08-15 13:01:00', 'Europe/Lisbon'),
            );
            $expectFullRefresh = true;

            $fullImport = app(EventReportSyncService::class)->sync($event, $admin);

            $this->assertSame('full', $fullImport->summary['document_fetch_mode'] ?? null);
            $this->assertSame(0, $fullImport->summary['reused_rows_count'] ?? null);
            $this->assertSame(3, $fullImport->summary['fetched_rows_count'] ?? null);
            $this->assertSame('14.0000', $fullImport->summary['sales_total'] ?? null);
            $this->assertSame(
                '2026-08-15T13:01:00+01:00',
                $fullImport->summary['last_full_document_sync_at'] ?? null,
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sync_uses_event_date_as_start_when_only_report_end_is_configured(): void
    {
        CarbonImmutable::setTestNow('2026-08-14 12:00:00');

        try {
            [$admin, $client] = $this->makeAdminClientContext();
            $application = $this->makeApplication();
            $event = Event::create([
                'client_id' => $client->id,
                'title' => 'Evento de varios dias',
                'event_date' => '2026-08-10 12:31:00',
                'report_starts_at' => null,
                'report_ends_at' => '2026-08-15 03:00:00',
                'is_active' => true,
            ]);

            $machine = ClientZoneSoftMachine::create([
                'client_id' => $client->id,
                'event_id' => $event->id,
                'zonesoft_application_id' => $application->id,
                'zs_client_id' => 'MULTI-DAY-CLIENT',
                'license' => 'Z11JSMZIYP',
                'store_id' => 1,
                'store_label' => 'Loja 1',
                'permissions' => 'API + All document interfaces',
                'is_active' => true,
                'last_validated_at' => now(),
            ]);

            EventReportImport::create([
                'event_id' => $event->id,
                'uploaded_by_user_id' => $admin->id,
                'import_strategy' => 'replace',
                'original_filename' => 'zonesoft-api',
                'stored_path' => 'zonesoft://sync',
                'mime_type' => 'application/json',
                'file_hash' => hash('sha256', 'unverified-empty-snapshot'),
                'headers' => [
                    'source' => 'zonesoft_api',
                    'machines' => [['id' => $machine->id]],
                ],
                'summary' => [
                    'source' => 'zonesoft_api',
                    'machines_count' => 1,
                    'failed_machines' => [],
                    'machine_warnings' => [],
                ],
                'imported_rows_count' => 0,
                'imported_at' => now()->subHour(),
                'is_active' => true,
                'status' => 'completed',
            ]);

            Http::fake([
                'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) {
                    $this->assertSame(
                        "loja = 1 and data >= '2026-08-10' and data <= '2026-08-15'",
                        $request->data()['document']['condition'] ?? null,
                    );

                    return Http::response([
                        'Response' => [
                            'StatusCode' => 200,
                            'StatusMessage' => 'OK',
                            'Content' => ['document' => []],
                        ],
                    ]);
                },
            ]);

            $import = app(EventReportSyncService::class)->sync($event, $admin);

            $this->assertSame('completed', $import->status);
            $this->assertSame(1, $import->summary['api_requests_count'] ?? null);
            $this->assertTrue($import->summary['historical_data_complete'] ?? false);
            $this->assertSame('2026-08-10T12:31:00+00:00', $import->summary['fetch_range']['start'] ?? null);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_negative_documents_apply_negative_sign_to_partial_payments(): void
    {
        config(['event-reports.zonesoft.complete_documents' => true]);

        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);
        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CREDIT-NOTE-CLIENT',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getInstances' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'document' => [[
                            'numero' => 1,
                            'doc' => 'NC',
                            'serie' => 'A2026',
                            'data' => '2026-06-20',
                            'datahora' => '2026-06-20 12:05:00',
                            'pagamento' => 3,
                            'total' => -1.20,
                            'pago' => 1,
                            'documentos_pagamento' => [[
                                'doc' => 'PG',
                                'serie' => 'A2026',
                                'numero' => 10,
                                'tipo' => 3,
                                'valor' => 1.20,
                            ]],
                            'vendas' => [[
                                'id' => 100,
                                'loja' => 1,
                                'numero' => 1,
                                'doc' => 'NC',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:05:00',
                                'codigo' => 7,
                                'descricao' => 'Agua s/Gas',
                                'qtd' => -1,
                                'valor' => -1.20,
                                'total' => -1.20,
                            ]],
                        ]],
                    ],
                ],
            ]),
        ]);

        $import = app(EventReportSyncService::class)->sync($event, $admin);
        $payment = $import->paymentDocuments()->firstOrFail();

        $this->assertSame('-1.2000', $import->summary['sales_total'] ?? null);
        $this->assertSame(-1.2, (float) $payment->getRawOriginal('total'));
        $this->assertFalse($payment->is_unallocated);
        $this->assertSame($machine->id, $payment->machine_id);
    }

    public function test_sync_retries_rate_limited_machines_in_serial_pass(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-ID-OK',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $rateLimitedMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-ID-RATE-LIMITED',
            'license' => 'Z11JSMZIYP',
            'store_id' => 2,
            'store_label' => 'Loja 2',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $documentAttempts = [];

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) use (&$documentAttempts) {
                $clientId = $request->header('X-ZS-CLIENT-ID')[0] ?? '';
                $documentAttempts[$clientId] = ($documentAttempts[$clientId] ?? 0) + 1;

                if ($clientId === 'CLIENT-ID-RATE-LIMITED' && $documentAttempts[$clientId] <= 4) {
                    return Http::response('You have exceeded the rate limit. Please try again later.', 429);
                }

                $storeId = str_contains($clientId, 'RATE-LIMITED') ? 2 : 1;

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [
                                [
                                    'numero' => 500 + $storeId,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => '2026-06-20',
                                    'datahora' => '2026-06-20 12:05:00',
                                    'pagamento' => 3,
                                    'total' => 8.7,
                                    'pago' => 1,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => function ($request) {
                $storeId = (int) (($request->header('X-ZS-CLIENT-ID')[0] ?? '') === 'CLIENT-ID-RATE-LIMITED' ? 2 : 1);

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'sale' => [
                                [
                                    'id' => $storeId,
                                    'loja' => $storeId,
                                    'numero' => 500 + $storeId,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => '2026-06-20',
                                    'datahora' => '2026-06-20 12:00:00',
                                    'codigo' => 730 + $storeId,
                                    'descricao' => 'Produto '.$storeId,
                                    'qtd' => 1,
                                    'valor' => 4.35,
                                    'desconto' => 0,
                                    'desconto2' => 0,
                                    'total' => 4.35,
                                    'posto' => 1,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/salesday/getInstances' => function ($request) {
                $storeId = (int) (($request->header('X-ZS-CLIENT-ID')[0] ?? '') === 'CLIENT-ID-RATE-LIMITED' ? 2 : 1);

                return $this->fakeSalesdayResponse([
                    [
                        'loja' => $storeId,
                        'data' => '2026-06-20',
                        'caixa' => 1,
                        'Open' => 1,
                        'fs' => 4.35,
                        'ft' => 0,
                        'movimento' => 4.35,
                        'num' => 4.35,
                        'deb' => 0,
                    ],
                ]);
            },
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->summary['machines_count'] ?? null);
        $this->assertSame([], $import->summary['failed_machines'] ?? []);
        $this->assertSame(5, $documentAttempts['CLIENT-ID-RATE-LIMITED'] ?? 0);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.zonesoft.org/v3/salesday/getInstances');

        $rateLimitedMachine->refresh();
        $this->assertNull($rateLimitedMachine->last_error);
    }

    public function test_sync_filters_sales_outside_precise_event_report_range(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Janela Horaria',
            'description' => 'Sincronizacao ZoneSoft com filtro horario',
            'event_date' => '2026-06-20 12:00:00',
            'report_starts_at' => '2026-06-20 12:00:00',
            'report_ends_at' => '2026-06-20 12:59:59',
            'is_active' => true,
        ]);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) {
                $this->assertSame(
                    "loja = 1 and data >= '2026-06-20' and data <= '2026-06-20'",
                    $request->data()['document']['condition'] ?? null,
                );

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [
                                ['numero' => 501, 'doc' => 'FS', 'serie' => 'A2026'],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'sale' => [
                            [
                                'id' => 1,
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 11:59:59',
                                'codigo' => 729,
                                'descricao' => 'Fora da janela',
                                'qtd' => 1,
                                'valor' => 1.00,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 1.00,
                            ],
                            [
                                'id' => 2,
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:05:00',
                                'codigo' => 730,
                                'descricao' => 'Dentro da janela',
                                'qtd' => 2,
                                'valor' => 4.8673,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 5.50,
                            ],
                            [
                                'id' => 3,
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 13:00:00',
                                'codigo' => 731,
                                'descricao' => 'Fora depois do fim',
                                'qtd' => 1,
                                'valor' => 2.00,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 2.00,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/salesday/getInstances' => $this->fakeSalesdayResponse([
                [
                    'loja' => 1,
                    'data' => '2026-06-20',
                    'caixa' => 1,
                    'Open' => 1,
                    'fs' => 5.50,
                ],
            ]),
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $import->imported_rows_count);
        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => '501',
            'product_code' => '730',
            'description' => 'Dentro da janela',
        ]);
        $this->assertDatabaseMissing('event_report_rows', [
            'event_id' => $event->id,
            'product_code' => '729',
        ]);
        $this->assertDatabaseMissing('event_report_rows', [
            'event_id' => $event->id,
            'product_code' => '731',
        ]);
    }

    /**
     * PERF-201: dispatch no longer forks one OS process per machine — every
     * machine is fetched concurrently in this same process via Http::pool().
     * Fifteen machines all succeeding here, with no Process/ProcessFactory
     * mock anywhere in this test, is itself the proof: if the old
     * invoke-serialized-closure mechanism were still in play, this would
     * try to spawn real child processes inside `php artisan test` and
     * either hang or fail.
     */
    public function test_sync_dispatches_many_machines_concurrently_without_process_forking(): void
    {
        config(['event-reports.zonesoft.complete_documents' => true]);

        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        $machineCount = 15;

        for ($storeId = 1; $storeId <= $machineCount; $storeId++) {
            ClientZoneSoftMachine::create([
                'client_id' => $client->id,
                'event_id' => $event->id,
                'zonesoft_application_id' => $application->id,
                'zs_client_id' => 'CLIENT-ID-'.$storeId,
                'license' => 'Z11JSMZIYP',
                'store_id' => $storeId,
                'store_label' => 'Loja '.$storeId,
                'permissions' => 'API + All document interfaces',
                'is_active' => true,
                'last_validated_at' => now(),
            ]);
        }

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getInstances' => function ($request) {
                $clientId = $request->header('X-ZS-CLIENT-ID')[0] ?? '';
                $storeId = (int) str_replace('CLIENT-ID-', '', $clientId);

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [[
                                'loja' => $storeId,
                                'numero' => 100 + $storeId,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:00:00',
                                'pagamento' => 3,
                                'total' => 1.0,
                                'pago' => 1,
                                'vendas' => [[
                                    'id' => $storeId,
                                    'loja' => $storeId,
                                    'numero' => 100 + $storeId,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => '2026-06-20',
                                    'datahora' => '2026-06-20 12:00:00',
                                    'codigo' => 700 + $storeId,
                                    'descricao' => 'Produto '.$storeId,
                                    'qtd' => 1,
                                    'valor' => 1.0,
                                    'total' => 1.0,
                                ]],
                            ]],
                        ],
                    ],
                ], 200);
            },
        ]);

        $import = app(EventReportSyncService::class)->sync($event, $admin);

        $this->assertSame('completed', $import->status);
        $this->assertSame($machineCount, $import->summary['machines_count'] ?? null);
        $this->assertSame($machineCount, $import->imported_rows_count);
        Http::assertSentCount($machineCount);

        for ($storeId = 1; $storeId <= $machineCount; $storeId++) {
            $this->assertDatabaseHas('event_report_rows', [
                'event_id' => $event->id,
                'document_number' => (string) (100 + $storeId),
                'product_code' => (string) (700 + $storeId),
            ]);
        }
    }

    /**
     * PERF-201's paginator pools one "next page" request per still-active
     * machine per round instead of one process per machine. This confirms
     * the round-based logic itself: a single machine whose document list
     * spans three pages (two full pages at the 250 limit, one short page)
     * gets all three rounds fetched and combined correctly.
     */
    public function test_document_pagination_across_multiple_rounds_combines_all_pages(): void
    {
        config(['event-reports.zonesoft.complete_documents' => true]);

        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'PAGINATED-CLIENT',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja Paginada',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        // PERF-103: pagination is by lastupdate keyset, not offset (see
        // EventReportSyncService::dedupeDocumentPage()) — this fake gives
        // each document a distinct, strictly increasing lastupdate (one
        // second apart) and honours "lastupdate >= 'X'" out of the request
        // condition, exactly like a real ZSAPI listing would, so the round
        // trips this test asserts are the same rounds production will make.
        $totalDocuments = 510;
        $baseTimestamp = strtotime('2026-06-20 12:00:00');
        $requestedBoundaries = [];

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getInstances' => function ($request) use (&$requestedBoundaries, $totalDocuments, $baseTimestamp) {
                $condition = (string) ($request->data()['document']['condition'] ?? '');
                $limit = (int) ($request->data()['document']['limit'] ?? 250);
                $boundaryTimestamp = null;

                if (preg_match("/lastupdate >= '([^']+)'/", $condition, $matches) === 1) {
                    $boundaryTimestamp = strtotime($matches[1]);
                }

                $requestedBoundaries[] = $matches[1] ?? null;

                $documents = [];

                for ($numero = 1; $numero <= $totalDocuments && count($documents) < $limit; $numero++) {
                    $lastupdateTimestamp = $baseTimestamp + $numero;

                    if ($boundaryTimestamp !== null && $lastupdateTimestamp < $boundaryTimestamp) {
                        continue;
                    }

                    $lastupdate = date('Y-m-d H:i:s', $lastupdateTimestamp);
                    $documents[] = [
                        'loja' => 1,
                        'numero' => $numero,
                        'doc' => 'FS',
                        'serie' => 'A2026',
                        'data' => '2026-06-20',
                        'datahora' => '2026-06-20 12:00:00',
                        'lastupdate' => $lastupdate,
                        'pagamento' => 3,
                        'total' => 1.0,
                        'pago' => 1,
                        'vendas' => [[
                            'id' => $numero,
                            'loja' => 1,
                            'numero' => $numero,
                            'doc' => 'FS',
                            'serie' => 'A2026',
                            'data' => '2026-06-20',
                            'datahora' => '2026-06-20 12:00:00',
                            'codigo' => 700,
                            'descricao' => 'Produto',
                            'qtd' => 1,
                            'valor' => 1.0,
                            'total' => 1.0,
                        ]],
                    ];
                }

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => $documents,
                        ],
                    ],
                ], 200);
            },
        ]);

        $import = app(EventReportSyncService::class)->sync($event, $admin);

        $this->assertSame('completed', $import->status);
        $this->assertSame($totalDocuments, $import->imported_rows_count);
        // 3 rounds: page 1 has no boundary yet, pages 2-3 carry the
        // previous page's tail lastupdate forward as the next lower bound.
        $this->assertCount(3, $requestedBoundaries);
        $this->assertNull($requestedBoundaries[0]);
        $this->assertNotNull($requestedBoundaries[1]);
        $this->assertNotNull($requestedBoundaries[2]);
        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => '1',
        ]);
        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => (string) $totalDocuments,
        ]);
    }

    public function test_document_pagination_does_not_skip_a_document_inserted_between_page_requests(): void
    {
        config(['event-reports.zonesoft.complete_documents' => true]);

        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CONCURRENT-CLIENT',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja Concorrente',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        // 260 documents already exist when the sync starts (round 1 fetches
        // the first 250, leaving a page boundary at document 250's
        // lastupdate). Once that first request has been served, a NEW
        // document (261) is "inserted" — its lastupdate is later than
        // everything already read, exactly like a real live sale recorded
        // while this sync is mid-flight. The offset-based pagination this
        // replaces (PERF-103) ordered by the business date `data`, so a
        // late-arriving row with an earlier `data` than the current offset
        // could land in an already-consumed page and never be read; a row
        // inserted with a fresh lastupdate cannot do that here — it always
        // sorts after the current boundary, into a page not yet visited.
        $existingDocuments = 260;
        $baseTimestamp = strtotime('2026-06-20 12:00:00');
        $concurrentDocumentInserted = false;
        $requestCount = 0;

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getInstances' => function ($request) use (
                &$concurrentDocumentInserted,
                &$requestCount,
                $existingDocuments,
                $baseTimestamp,
            ) {
                $requestCount++;
                $condition = (string) ($request->data()['document']['condition'] ?? '');
                $limit = (int) ($request->data()['document']['limit'] ?? 250);
                $boundaryTimestamp = null;

                if (preg_match("/lastupdate >= '([^']+)'/", $condition, $matches) === 1) {
                    $boundaryTimestamp = strtotime($matches[1]);
                }

                // The concurrent insert only becomes visible to the API
                // after the first page has already been served — modelling
                // it landing in the gap between two page requests.
                $totalAvailable = $requestCount > 1 && $concurrentDocumentInserted
                    ? $existingDocuments + 1
                    : $existingDocuments;

                $documents = [];

                for ($numero = 1; $numero <= $totalAvailable && count($documents) < $limit; $numero++) {
                    $lastupdateTimestamp = $baseTimestamp + $numero;

                    if ($boundaryTimestamp !== null && $lastupdateTimestamp < $boundaryTimestamp) {
                        continue;
                    }

                    $documents[] = [
                        'loja' => 1,
                        'numero' => $numero,
                        'doc' => 'FS',
                        'serie' => 'A2026',
                        'data' => '2026-06-20',
                        'datahora' => '2026-06-20 12:00:00',
                        'lastupdate' => date('Y-m-d H:i:s', $lastupdateTimestamp),
                        'pagamento' => 3,
                        'total' => 1.0,
                        'pago' => 1,
                        'vendas' => [[
                            'id' => $numero,
                            'loja' => 1,
                            'numero' => $numero,
                            'doc' => 'FS',
                            'serie' => 'A2026',
                            'data' => '2026-06-20',
                            'datahora' => '2026-06-20 12:00:00',
                            'codigo' => 700,
                            'descricao' => 'Produto',
                            'qtd' => 1,
                            'valor' => 1.0,
                            'total' => 1.0,
                        ]],
                    ];
                }

                $concurrentDocumentInserted = true;

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => $documents,
                        ],
                    ],
                ], 200);
            },
        ]);

        $import = app(EventReportSyncService::class)->sync($event, $admin);

        $this->assertSame('completed', $import->status);
        $this->assertSame($existingDocuments + 1, $import->imported_rows_count);
        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => (string) ($existingDocuments + 1),
        ]);
    }

    public function test_document_pagination_aborts_when_more_documents_than_the_page_limit_share_one_lastupdate(): void
    {
        config(['event-reports.zonesoft.complete_documents' => true]);

        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'STUCK-CLIENT',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja Presa',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        // 300 documents, all sharing the exact same lastupdate — more than
        // the 250-per-page cap, which keyset pagination cannot get past
        // (see EventReportSyncService::isStuckDocumentPage()). Every
        // request gets the identical first 250-by-numero page back,
        // regardless of the lastupdate boundary requested.
        Http::fake([
            'https://api.zonesoft.org/v3/documents/getInstances' => function () {
                $documents = [];

                for ($numero = 1; $numero <= 250; $numero++) {
                    $documents[] = [
                        'loja' => 1,
                        'numero' => $numero,
                        'doc' => 'FS',
                        'serie' => 'A2026',
                        'data' => '2026-06-20',
                        'datahora' => '2026-06-20 12:00:00',
                        'lastupdate' => '2026-06-20 12:00:00',
                        'pagamento' => 3,
                        'total' => 1.0,
                        'pago' => 1,
                        'vendas' => [],
                    ];
                }

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => $documents,
                        ],
                    ],
                ], 200);
            },
        ]);

        try {
            app(EventReportSyncService::class)->sync($event, $admin);
            $this->fail('Esperava que a sincronizacao falhasse com a paginacao presa.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'paginacao de documentos presa',
                $exception->validator->errors()->first('integration'),
            );
        }

        // The failed sync must not have left the event with any rows —
        // nothing was ever published (PERF-101's publish transaction only
        // runs once every machine has a clean fetch).
        $this->assertDatabaseCount('event_report_rows', 0);
    }

    public function test_admin_cannot_start_sync_when_event_already_has_processing_import(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'processing-sync'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 0],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertSessionHasErrors([
                'integration' => 'Ja existe uma sincronizacao em andamento. Aguarde a conclusao antes de iniciar outra.',
            ]);

        $this->assertSame(1, EventReportImport::query()->count());
    }

    public function test_admin_can_restart_sync_when_previous_processing_import_is_stale(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $staleImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'stale-processing-sync'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 0],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);

        EventReportImport::query()
            ->whereKey($staleImport->id)
            ->update([
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'document' => [
                            ['numero' => 501, 'doc' => 'FS', 'serie' => 'A2026'],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'sale' => [
                            [
                                'id' => 1,
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:00:00',
                                'codigo' => 730,
                                'descricao' => 'Agua',
                                'qtd' => 1,
                                'valor' => 2.75,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 2.75,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/salesday/getInstances' => $this->fakeSalesdayResponse(),
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $staleImport->refresh();

        $this->assertSame('failed', $staleImport->status);
        $this->assertSame(
            'A sincronizacao anterior foi marcada como falhada por falta de conclusao.',
            $staleImport->summary['error'] ?? null,
        );
        $this->assertSame(2, EventReportImport::query()->count());
        $this->assertSame(1, EventReportImport::query()->where('status', 'completed')->count());
    }

    public function test_sync_replaces_previous_active_snapshot_and_event_index_uses_only_latest_sync(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $previousImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'previous-sync'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 1],
            'imported_rows_count' => 1,
            'imported_at' => now()->subDay(),
            'is_active' => true,
            'status' => 'completed',
        ]);

        EventReportRow::create([
            'event_id' => $event->id,
            'event_report_import_id' => $previousImport->id,
            'machine_id' => $machine->id,
            'source_sheet' => 'zonesoft:test',
            'source_row_number' => 1,
            'store_code' => '1',
            'store_name' => 'Loja 1 - POS 1',
            'sale_date' => '2026-06-19',
            'sale_datetime' => '2026-06-19 12:00:00',
            'doc_type' => 'FS',
            'document_series' => 'OLD',
            'document_number' => '499',
            'line_key' => 'legacy:1',
            'value' => '1.0000',
            'total' => '1.2000',
            'discount' => '0.0000',
            'quantity' => '1.0000',
            'product_code' => '700',
            'description' => 'Produto Antigo',
            'raw_row' => ['legacy' => true],
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'document' => [
                            ['numero' => 600, 'doc' => 'FS', 'serie' => 'A2026'],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'sale' => [
                            [
                                'id' => 10,
                                'loja' => 1,
                                'numero' => 600,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:00:00',
                                'codigo' => 730,
                                'descricao' => 'Agua',
                                'qtd' => 1,
                                'valor' => 2.4336,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 2.75,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/salesday/getInstances' => $this->fakeSalesdayResponse(),
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $previousImport->refresh();

        $this->assertFalse($previousImport->is_active);
        $this->assertSame(2, EventReportImport::query()->count());
        $this->assertSame(1, EventReportImport::query()->where('is_active', true)->count());
        // The full-mode fetch only returned document 600 for this machine —
        // PERF-101's reconcileVanishedDocuments() must drop the old
        // series-OLD/499 document since it no longer exists upstream.
        $this->assertDatabaseMissing('event_report_rows', [
            'event_id' => $event->id,
            'document_series' => 'OLD',
            'document_number' => '499',
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Events/Index')
            ->has('events', 1)
            ->where('events.0.report_summary.active_syncs_count', 0)
            ->where('events.0.report_summary.active_rows_count', 1)
            ->where('events.0.report_summary.total', 2.75)
            ->where('events.0.report_summary.machines_count', 1));
    }

    public function test_sync_refetches_previous_business_day_after_midnight(): void
    {
        CarbonImmutable::setTestNow('2026-06-28 12:00:00');

        try {
            [$admin, $client] = $this->makeAdminClientContext();
            $application = $this->makeApplication();
            $event = Event::create([
                'client_id' => $client->id,
                'title' => 'Evento de dois dias',
                'description' => 'Sincronizacao incremental',
                'event_date' => '2026-06-27 12:00:00',
                'report_starts_at' => '2026-06-27 00:00:00',
                'report_ends_at' => '2026-06-28 23:59:59',
                'is_active' => true,
            ]);
            $machine = ClientZoneSoftMachine::create([
                'client_id' => $client->id,
                'event_id' => $event->id,
                'zonesoft_application_id' => $application->id,
                'zs_client_id' => 'INCREMENTAL-CLIENT-ID',
                'license' => 'Z11JSMZIYP',
                'store_id' => 1,
                'store_label' => 'Loja 1',
                'permissions' => 'API + All document interfaces',
                'is_active' => true,
                'last_validated_at' => now(),
            ]);
            $previousImport = EventReportImport::create([
                'event_id' => $event->id,
                'uploaded_by_user_id' => $admin->id,
                'import_strategy' => 'replace',
                'original_filename' => 'zonesoft-api',
                'stored_path' => 'zonesoft://sync',
                'mime_type' => 'application/json',
                'file_hash' => hash('sha256', 'incremental-previous'),
                'headers' => [
                    'source' => 'zonesoft_api',
                    'machines' => [
                        ['id' => $machine->id],
                    ],
                ],
                'summary' => [
                    'source' => 'zonesoft_api',
                    'machines_count' => 1,
                    'failed_machines' => [],
                    'machine_warnings' => [],
                    'payment_documents' => [],
                ],
                'imported_rows_count' => 1,
                'imported_at' => now()->subHour(),
                'is_active' => true,
                'status' => 'completed',
            ]);
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $previousImport->id,
                'machine_id' => $machine->id,
                'source_sheet' => 'zonesoft:INCREMENTAL-CLIENT-ID',
                'source_row_number' => 1,
                'store_code' => '1',
                'store_name' => 'Loja 1',
                'sale_date' => '2026-06-27',
                'sale_datetime' => '2026-06-27 12:00:00',
                'doc_type' => 'FS',
                'document_series' => 'A2026',
                'document_number' => '100',
                'line_key' => 'id:1',
                'value' => '5.0000',
                'total' => '5.0000',
                'discount' => '0.0000',
                'quantity' => '1.0000',
                'product_code' => '700',
                'description' => 'Produto de ontem',
                'raw_row' => [
                    'machine_id' => $machine->id,
                    'machine_client_id' => $machine->zs_client_id,
                    'id' => 1,
                ],
            ]);

            Http::fake([
                'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) {
                    $this->assertSame(
                        "loja = 1 and data >= '2026-06-27' and data <= '2026-06-28'",
                        $request->data()['document']['condition'] ?? null,
                    );

                    return Http::response([
                        'Response' => [
                            'StatusCode' => 200,
                            'StatusMessage' => 'OK',
                            'Content' => [
                                'document' => [
                                    ['numero' => 100, 'doc' => 'FS', 'serie' => 'A2026'],
                                    ['numero' => 101, 'doc' => 'FS', 'serie' => 'A2026'],
                                    ['numero' => 102, 'doc' => 'FS', 'serie' => 'A2026'],
                                ],
                            ],
                        ],
                    ]);
                },
                'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => function ($request) {
                    $documentNumber = (int) ($request->data()['sale']['numero'] ?? 0);
                    $sales = [
                        100 => [1, '2026-06-27', '2026-06-27 12:00:00', 700, 'Produto de ontem', 5],
                        101 => [2, '2026-06-27', '2026-06-27 23:58:00', 701, 'Venda tardia', 3],
                        102 => [3, '2026-06-28', '2026-06-28 10:00:00', 702, 'Produto de hoje', 7],
                    ];
                    [$id, $date, $dateTime, $productCode, $description, $total] = $sales[$documentNumber];

                    return Http::response([
                        'Response' => [
                            'StatusCode' => 200,
                            'StatusMessage' => 'OK',
                            'Content' => [
                                'sale' => [[
                                    'id' => $id,
                                    'loja' => 1,
                                    'numero' => $documentNumber,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => $date,
                                    'datahora' => $dateTime,
                                    'codigo' => $productCode,
                                    'descricao' => $description,
                                    'qtd' => 1,
                                    'valor' => $total,
                                    'total' => $total,
                                ]],
                            ],
                        ],
                    ]);
                },
            ]);

            $import = app(EventReportSyncService::class)->sync($event, $admin);

            $this->assertSame(3, $import->imported_rows_count);
            $this->assertSame(0, $import->summary['reused_rows_count'] ?? null);
            $this->assertSame(3, $import->summary['fetched_rows_count'] ?? null);
            $this->assertDatabaseHas('event_report_rows', [
                'event_report_import_id' => $import->id,
                'sale_date' => '2026-06-27',
                'product_code' => '700',
            ]);
            $this->assertDatabaseHas('event_report_rows', [
                'event_report_import_id' => $import->id,
                'sale_date' => '2026-06-27',
                'product_code' => '701',
                'description' => 'Venda tardia',
            ]);
            $this->assertDatabaseHas('event_report_rows', [
                'event_report_import_id' => $import->id,
                'sale_date' => '2026-06-28',
                'product_code' => '702',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sync_does_not_publish_when_one_machine_is_unauthorized(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'VALID-CLIENT-ID',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $invalidMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'INVALID-CLIENT-ID',
            'license' => 'Z11JSMZIYP',
            'store_id' => 2,
            'store_label' => 'Loja 2',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) {
                if (($request->header('X-ZS-CLIENT-ID')[0] ?? null) === 'INVALID-CLIENT-ID') {
                    return Http::response([
                        'Response' => [
                            'StatusCode' => 401,
                            'StatusMessage' => 'Unauthorized',
                            'Content' => [
                                'document' => null,
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [
                                ['numero' => 501, 'doc' => 'FS', 'serie' => 'A2026'],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'sale' => [
                            [
                                'id' => 1,
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:00:00',
                                'codigo' => 730,
                                'descricao' => 'Agua',
                                'qtd' => 1,
                                'valor' => 2.4336,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 2.75,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/salesday/getInstances' => $this->fakeSalesdayResponse(),
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.events.index'))
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'))
            ->assertSessionHasErrors('integration');

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame('failed', $import->status);
        $this->assertFalse($import->is_active);
        $this->assertSame(0, $import->imported_rows_count);
        $this->assertSame(1, $import->summary['machines_count'] ?? null);
        $this->assertCount(1, $import->summary['failed_machines'] ?? []);
        $this->assertDatabaseMissing('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => '501',
            'product_code' => '730',
        ]);
        $invalidMachine->refresh();
        $this->assertSame('Unauthorized', $invalidMachine->last_error);
    }

    public function test_sync_does_not_publish_when_one_document_sale_fails(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);
        $previousImport = $this->makeActiveReportImport($event, $admin);

        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'VALID-CLIENT-ID',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Foodtruck',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'document' => [
                            ['numero' => 501, 'doc' => 'FS', 'serie' => 'A2026'],
                            ['numero' => 502, 'doc' => 'FS', 'serie' => 'A2026'],
                        ],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => function ($request) {
                if (($request->data()['sale']['numero'] ?? null) === 502) {
                    return Http::response([
                        'Response' => [
                            'StatusCode' => 401,
                            'StatusMessage' => 'Unauthorized',
                            'Content' => [
                                'sale' => null,
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'sale' => [
                                [
                                    'id' => 1,
                                    'loja' => 1,
                                    'numero' => 501,
                                    'doc' => 'FS',
                                    'serie' => 'A2026',
                                    'data' => '2026-06-20',
                                    'datahora' => '2026-06-20 12:00:00',
                                    'codigo' => 730,
                                    'descricao' => 'Agua',
                                    'qtd' => 1,
                                    'valor' => 2.4336,
                                    'desconto' => 0,
                                    'desconto2' => 0,
                                    'total' => 2.75,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/salesday/getInstances' => $this->fakeSalesdayResponse(),
        ]);

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.events.index'))
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'))
            ->assertSessionHasErrors('integration');

        $response->assertSessionHasErrors([
            'integration' => 'A sincronizacao nao foi publicada porque ficou incompleta: 0 maquina(s) falharam e 1 maquina(s) tiveram documentos com erro. Documentos com erro: Foodtruck (Store 1)',
        ]);

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame('failed', $import->status);
        $this->assertFalse($import->is_active);
        $this->assertSame(0, $import->imported_rows_count);
        $this->assertSame(1, $import->summary['machines_count'] ?? null);
        $this->assertCount(0, $import->summary['failed_machines'] ?? []);
        $this->assertCount(1, $import->summary['machine_warnings'] ?? []);
        $this->assertSame('Foodtruck', $import->summary['machine_warnings'][0]['store_label'] ?? null);
        $this->assertDatabaseMissing('event_report_rows', [
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'document_number' => '501',
            'product_code' => '730',
        ]);
        $this->assertTrue($previousImport->fresh()->is_active);
        $this->assertSame(1, EventReportImport::query()->where('is_active', true)->count());

        $machine->refresh();
        $this->assertStringContainsString('Falha parcial em 1 documento(s)', $machine->last_error ?? '');
        $this->assertStringContainsString('FS / A2026 / 502', $machine->last_error ?? '');
    }

    public function test_zonesoft_client_retries_a_transient_connection_failure(): void
    {
        $application = $this->makeApplication();

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => Http::sequence()
                ->pushFailedConnection('Connection timed out')
                ->push([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [],
                        ],
                    ],
                ]),
        ]);

        $result = app(ZoneSoftApiClient::class)->post(
            $application,
            'CLIENT-ID',
            'documents',
            'getDocumentsHeaders',
            'document',
            ['limit' => 1],
        );

        $this->assertSame([], $result['document'] ?? null);
        Http::assertSentCount(2);
    }

    public function test_zonesoft_sales_batch_retries_a_transient_unauthorized_response(): void
    {
        $application = $this->makeApplication();
        $attempts = 0;

        Http::fake([
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => function () use (&$attempts) {
                $attempts++;

                if ($attempts === 1) {
                    return Http::response([
                        'Response' => [
                            'StatusCode' => 401,
                            'StatusMessage' => 'Unauthorized',
                            'Content' => ['sale' => null],
                        ],
                    ]);
                }

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'sale' => [
                                ['id' => 1, 'codigo' => 730],
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        $results = app(ZoneSoftApiClient::class)->postMany(
            $application,
            'CLIENT-ID',
            'sales',
            'getInstancesFromDocument',
            'sale',
            [
                ['doc' => 'FS', 'serie' => 'A2026', 'numero' => 1],
            ],
            2,
        );

        $this->assertSame(2, $attempts);
        $this->assertSame(730, $results[0]['sale'][0]['codigo'] ?? null);
    }

    public function test_stale_worker_cannot_publish_after_its_import_was_failed(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $staleImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'stale-worker'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api'],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'failed',
        ]);
        $currentImport = $this->makeActiveReportImport($event, $admin);

        try {
            app(EventReportSyncService::class)->run($staleImport);
            $this->fail('The stale worker should not be allowed to run.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ja nao esta em processamento', $exception->getMessage());
        }

        $this->assertTrue($currentImport->fresh()->is_active);
        $this->assertSame('failed', $staleImport->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_failed_queue_job_closes_a_processing_import(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $processingImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'failed-job'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api'],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);

        // PERF-101: event_report_rows/event_report_payment_documents are
        // only ever written inside the finalization transaction, so a
        // processing import that never got that far has nothing staged to
        // clean up — there is no reachable state anymore where a row is
        // tied to a still-processing import.
        (new SyncEventReportJob($processingImport->id, $event->id))
            ->failed(new \RuntimeException('Worker stopped'));

        $processingImport->refresh();

        $this->assertSame('failed', $processingImport->status);
        $this->assertFalse($processingImport->is_active);
        $this->assertSame('Worker stopped', $processingImport->summary['error'] ?? null);
        $this->assertSame(0, EventReportRow::query()->where('event_id', $event->id)->count());
    }

    public function test_sync_job_uses_the_database_worker_with_outbound_access(): void
    {
        $job = new SyncEventReportJob(10, 20);

        $this->assertSame('database', $job->connection);
        $this->assertSame(900, config('queue.connections.database.retry_after'));
    }

    /**
     * PERF-101 replaces the old copy-then-sweep design (where a superseded
     * import's rows were deleted by a dedicated cleanup pass) with direct
     * upsert into a durable, event-scoped table. The equivalent guarantee to
     * protect now is stronger: a sync that fails partway through must never
     * touch a single row of the data that was already published — not "the
     * old snapshot gets swept eventually", but "nothing not part of this
     * cycle's delta is written or deleted at all".
     */
    public function test_failed_partial_sync_leaves_previously_published_rows_completely_untouched(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);
        $previousImport = $this->makeActiveReportImport($event, $admin);

        $validMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'VALID-CLIENT-ID',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $invalidMachine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'INVALID-CLIENT-ID',
            'license' => 'Z11JSMZIYP',
            'store_id' => 2,
            'store_label' => 'Loja 2',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $existingRow = EventReportRow::create([
            'event_id' => $event->id,
            'event_report_import_id' => $previousImport->id,
            'machine_id' => $validMachine->id,
            'source_sheet' => 'zonesoft:VALID-CLIENT-ID',
            'source_row_number' => 1,
            'store_code' => '1',
            'store_name' => 'Loja 1',
            'sale_date' => '2026-06-19',
            'sale_datetime' => '2026-06-19 12:00:00',
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => '900',
            'line_key' => 'id:900',
            'value' => '1.0000',
            'total' => '1.0000',
            'discount' => '0.0000',
            'quantity' => '1.0000',
            'product_code' => '700',
            'description' => 'Ja publicado',
            'raw_row' => ['machine_id' => $validMachine->id, 'id' => 900],
        ]);
        $existingRowUpdatedAt = $existingRow->updated_at;

        Http::fake([
            'https://api.zonesoft.org/v3/documents/getDocumentsHeaders' => function ($request) {
                if (($request->header('X-ZS-CLIENT-ID')[0] ?? null) === 'INVALID-CLIENT-ID') {
                    return Http::response([
                        'Response' => [
                            'StatusCode' => 401,
                            'StatusMessage' => 'Unauthorized',
                            'Content' => ['document' => null],
                        ],
                    ], 200);
                }

                return Http::response([
                    'Response' => [
                        'StatusCode' => 200,
                        'StatusMessage' => 'OK',
                        'Content' => [
                            'document' => [
                                ['numero' => 901, 'doc' => 'FS', 'serie' => 'A2026'],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://api.zonesoft.org/v3/sales/getInstancesFromDocument' => Http::response([
                'Response' => [
                    'StatusCode' => 200,
                    'StatusMessage' => 'OK',
                    'Content' => [
                        'sale' => [[
                            'id' => 901,
                            'loja' => 1,
                            'numero' => 901,
                            'doc' => 'FS',
                            'serie' => 'A2026',
                            'data' => '2026-06-20',
                            'datahora' => '2026-06-20 12:00:00',
                            'codigo' => 730,
                            'descricao' => 'Documento novo desta tentativa',
                            'qtd' => 1,
                            'valor' => 2.4336,
                            'desconto' => 0,
                            'desconto2' => 0,
                            'total' => 2.75,
                        ]],
                    ],
                ],
            ], 200),
            'https://api.zonesoft.org/v3/salesday/getInstances' => $this->fakeSalesdayResponse(),
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.events.index'))
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'))
            ->assertSessionHasErrors('integration');

        $import = EventReportImport::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $import->status);

        // The valid machine's document (901) must never have been written —
        // the whole cycle failed because the other machine did, and nothing
        // is written until every required machine has succeeded.
        $this->assertDatabaseMissing('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => '901',
        ]);

        // The row that was already durably there before this attempt must
        // be byte-for-byte untouched: same id, same updated_at, same values.
        $existingRow->refresh();
        $this->assertTrue($existingRow->updated_at->equalTo($existingRowUpdatedAt));
        $this->assertSame('Ja publicado', $existingRow->description);
        $this->assertSame(1, EventReportRow::query()->where('event_id', $event->id)->count());
    }

    /**
     * @return array{0: User, 1: Client}
     */
    private function makeAdminClientContext(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Relatorio',
            'business_name' => 'Operacao Evento',
            'address' => 'Rua do Relatorio',
            'phone' => '+351 930000001',
            'is_active' => true,
        ]);

        return [$admin, $client];
    }

    private function makeApplication(): ZoneSoftApplication
    {
        return ZoneSoftApplication::create([
            'name' => 'ZoneSoft Principal',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'app-key-123',
            'app_secret' => 'secret-123',
            'is_active' => true,
        ]);
    }

    private function makeEvent(Client $client): Event
    {
        return Event::create([
            'client_id' => $client->id,
            'title' => 'Evento com Relatorio',
            'description' => 'Sincronizacao ZoneSoft',
            'event_date' => '2026-06-20 12:00:00',
            'report_starts_at' => '2026-06-20 00:00:00',
            'report_ends_at' => '2026-06-20 23:59:59',
            'is_active' => true,
        ]);
    }

    private function makeActiveReportImport(Event $event, User $admin): EventReportImport
    {
        return EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'active-sync-'.$event->id.'-'.microtime(true)),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 1],
            'imported_rows_count' => 0,
            'imported_at' => now()->subMinute(),
            'is_active' => true,
            'status' => 'completed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeDocumentFixture(
        int $documentNumber,
        float $total,
        int $productCode,
        string $description,
    ): array {
        return [
            'loja' => 1,
            'numero' => $documentNumber,
            'doc' => 'FS',
            'serie' => 'A2026',
            'data' => '2026-08-13',
            'datahora' => '2026-08-13 20:00:00',
            'pagamento' => 3,
            'total' => $total,
            'pago' => 1,
            'vendas' => [[
                'id' => $documentNumber,
                'loja' => 1,
                'numero' => $documentNumber,
                'doc' => 'FS',
                'serie' => 'A2026',
                'data' => '2026-08-13',
                'datahora' => '2026-08-13 20:00:00',
                'codigo' => $productCode,
                'descricao' => $description,
                'qtd' => 1,
                'valor' => $total,
                'total' => $total,
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function fakeSalesdayResponse(array $records = [])
    {
        return Http::response([
            'Response' => [
                'StatusCode' => 200,
                'StatusMessage' => 'OK',
                'Content' => [
                    'salesday' => $records,
                ],
            ],
        ], 200);
    }
}
