<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncEventReportJob;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\EventReportSyncService;
use App\Services\ZoneSoft\ZoneSoftApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_admin_can_save_zonesoft_application_and_machine(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.integrations.application.save', $event), [
                'name' => 'ZoneSoft Principal',
                'base_url' => 'https://api.zonesoft.org/v3',
                'app_key' => 'app-key-123',
                'app_secret' => 'secret-123',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.events.integrations.show', $event));

        $application = ZoneSoftApplication::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('admin.events.integrations.machines.store', $event), [
                'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
                'license' => 'Z11JSMZIYP',
                'store_id' => 1,
                'store_label' => 'Loja 1 (PT)',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.events.integrations.show', $event));

        $this->assertDatabaseHas('zonesoft_applications', [
            'id' => $application->id,
            'name' => 'ZoneSoft Principal',
            'app_key' => 'app-key-123',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Loja 1 (PT)',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);
    }

    public function test_each_event_has_its_own_zonesoft_integrations(): void
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
            'event_id' => $firstEvent->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SAME-CLIENT-ID',
            'store_id' => 1,
        ]);
        $this->assertDatabaseHas('client_zonesoft_machines', [
            'client_id' => $client->id,
            'event_id' => $secondEvent->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SAME-CLIENT-ID',
            'store_id' => 1,
        ]);
        $this->assertSame(1, $firstEvent->zonesoftMachines()->count());
        $this->assertSame(1, $secondEvent->zonesoftMachines()->count());
        $firstMachine = $firstEvent->zonesoftMachines()->firstOrFail();

        $this
            ->actingAs($admin)
            ->put(route('admin.events.integrations.machines.update', [
                $secondEvent,
                $firstMachine,
            ]), [
                'zs_client_id' => 'SAME-CLIENT-ID',
                'license' => 'EVENT-LICENSE',
                'store_id' => 1,
                'store_label' => 'Tentativa cruzada',
                'is_active' => true,
            ])
            ->assertNotFound();

        $this
            ->actingAs($admin)
            ->get(route('admin.events.integrations.show', $firstEvent))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/Integrations')
                ->where('event.id', $firstEvent->id)
                ->where('event.title', 'Primeiro evento')
                ->where('client.id', $client->id)
                ->has('machines', 1)
                ->where('machines.0.store_label', 'Máquina '.$firstEvent->id));
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
            ->get(route('admin.events.integrations.show', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/Integrations')
                ->where('application.has_secret', true)
                ->where('application.has_usable_secret', false)
                ->where('application.requires_secret_reconfiguration', true));

        $this
            ->actingAs($admin)
            ->from(route('admin.events.integrations.show', $event))
            ->post(route('admin.events.integrations.application.save', $event), [
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
            'source_sheet' => 'zonesoft:test',
            'source_row_number' => 1,
            'store_code' => '1',
            'store_name' => 'Loja 1 - POS 1',
            'sale_date' => '2026-06-19',
            'sale_datetime' => '2026-06-19 12:00:00',
            'doc_type' => 'FS',
            'document_series' => 'OLD',
            'document_number' => '499',
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
        $this->assertDatabaseMissing('event_report_rows', [
            'event_report_import_id' => $previousImport->id,
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
                'source_sheet' => 'zonesoft:INCREMENTAL-CLIENT-ID',
                'source_row_number' => 1,
                'store_code' => '1',
                'store_name' => 'Loja 1',
                'sale_date' => '2026-06-27',
                'sale_datetime' => '2026-06-27 12:00:00',
                'doc_type' => 'FS',
                'document_series' => 'A2026',
                'document_number' => '100',
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
        $this->assertCount(0, $import->summary['failed_machines'] ?? []);
        $this->assertCount(1, $import->summary['machine_warnings'] ?? []);
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
        EventReportRow::create([
            'event_id' => $event->id,
            'event_report_import_id' => $processingImport->id,
            'source_sheet' => 'zonesoft:staged-test',
            'source_row_number' => 1,
            'document_number' => 'STAGED-1',
            'total' => '2.7500',
            'raw_row' => ['staged' => true],
        ]);

        (new SyncEventReportJob($processingImport->id, $event->id))
            ->failed(new \RuntimeException('Worker stopped'));

        $processingImport->refresh();

        $this->assertSame('failed', $processingImport->status);
        $this->assertFalse($processingImport->is_active);
        $this->assertSame('Worker stopped', $processingImport->summary['error'] ?? null);
        $this->assertDatabaseMissing('event_report_rows', [
            'event_report_import_id' => $processingImport->id,
            'document_number' => 'STAGED-1',
        ]);
    }

    public function test_sync_job_uses_the_database_worker_with_outbound_access(): void
    {
        $job = new SyncEventReportJob(10, 20);

        $this->assertSame('database', $job->connection);
        $this->assertSame(900, config('queue.connections.database.retry_after'));
    }

    public function test_superseded_cleanup_preserves_rows_being_staged_by_a_new_sync(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);
        $activeImport = $this->makeActiveReportImport($event, $admin);
        $supersededImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://old-sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'old-sync'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api'],
            'imported_rows_count' => 1,
            'imported_at' => now()->subMinute(),
            'is_active' => false,
            'status' => 'completed',
        ]);
        $processingImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://new-sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'new-sync'),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api'],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);

        foreach ([
            [$activeImport, 'ACTIVE-1'],
            [$supersededImport, 'OLD-1'],
            [$processingImport, 'STAGING-1'],
        ] as [$import, $documentNumber]) {
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $import->id,
                'source_sheet' => 'zonesoft:cleanup-test',
                'source_row_number' => 1,
                'document_number' => $documentNumber,
                'total' => '1.0000',
                'raw_row' => ['cleanup_test' => true],
            ]);
        }

        $cleanup = new \ReflectionMethod(EventReportSyncService::class, 'cleanupSupersededRows');
        $cleanup->invoke(app(EventReportSyncService::class), $event->id, $activeImport->id);

        $this->assertDatabaseHas('event_report_rows', [
            'event_report_import_id' => $activeImport->id,
            'document_number' => 'ACTIVE-1',
        ]);
        $this->assertDatabaseMissing('event_report_rows', [
            'event_report_import_id' => $supersededImport->id,
            'document_number' => 'OLD-1',
        ]);
        $this->assertDatabaseHas('event_report_rows', [
            'event_report_import_id' => $processingImport->id,
            'document_number' => 'STAGING-1',
        ]);
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
