<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventReportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_zonesoft_application_and_machine(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();

        $this
            ->actingAs($admin)
            ->post(route('admin.clients.integrations.application.save', $client), [
                'name' => 'ZoneSoft Principal',
                'base_url' => 'https://api.zonesoft.org/v3',
                'app_key' => 'app-key-123',
                'app_secret' => 'secret-123',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.clients.integrations.show', $client));

        $application = ZoneSoftApplication::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('admin.clients.integrations.machines.store', $client), [
                'zs_client_id' => 'B3FC7C254EBDD7505C9CFA30468213B0',
                'license' => 'Z11JSMZIYP',
                'store_id' => 1,
                'store_label' => 'Loja 1 (PT)',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.clients.integrations.show', $client));

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
    }

    public function test_admin_can_discover_stores_for_client_id(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
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
            ->post(route('admin.clients.integrations.discover-stores', $client), [
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

    public function test_admin_can_sync_event_report_from_zonesoft_api(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
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
                $this->assertSame('loja = 1', $request->data()['document']['condition'] ?? null);

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
                                'qtd' => 2,
                                'valor' => 4.8673,
                                'desconto' => 0,
                                'desconto2' => 0,
                                'total' => 5.5,
                                'posto' => 1,
                            ],
                            [
                                'id' => 2,
                                'loja' => 1,
                                'numero' => 501,
                                'doc' => 'FS',
                                'serie' => 'A2026',
                                'data' => '2026-06-20',
                                'datahora' => '2026-06-20 12:05:00',
                                'codigo' => 731,
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
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->firstOrFail();

        $this->assertSame('zonesoft-api', $import->original_filename);
        $this->assertTrue($import->is_active);
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported_rows_count);
        $this->assertSame('zonesoft_api', $import->summary['source'] ?? null);
        $this->assertSame(1, $import->summary['machines_count'] ?? null);

        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'product_code' => '730',
            'description' => 'Agua',
            'store_name' => 'Loja 1 - POS 1',
        ]);
    }

    public function test_sync_replaces_previous_active_snapshot_and_event_index_uses_only_latest_sync(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
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
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $previousImport->refresh();

        $this->assertFalse($previousImport->is_active);
        $this->assertSame(2, EventReportImport::query()->count());
        $this->assertSame(1, EventReportImport::query()->where('is_active', true)->count());

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Events/Index')
            ->has('events', 1)
            ->where('events.0.report_summary.active_syncs_count', 1)
            ->where('events.0.report_summary.active_rows_count', 1)
            ->where('events.0.report_summary.machines_count', 1));
    }

    public function test_sync_skips_unauthorized_machine_and_keeps_successful_rows(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
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
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $import->imported_rows_count);
        $this->assertSame(1, $import->summary['machines_count'] ?? null);
        $this->assertCount(1, $import->summary['failed_machines'] ?? []);
        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => '501',
            'product_code' => '730',
        ]);
        $invalidMachine->refresh();
        $this->assertSame('Unauthorized', $invalidMachine->last_error);
    }

    public function test_sync_keeps_rows_when_one_document_sale_fails(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $application = $this->makeApplication();
        $event = $this->makeEvent($client);

        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
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
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event))
            ->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $import->imported_rows_count);
        $this->assertSame(1, $import->summary['machines_count'] ?? null);
        $this->assertCount(0, $import->summary['failed_machines'] ?? []);
        $this->assertCount(1, $import->summary['machine_warnings'] ?? []);
        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'document_number' => '501',
            'product_code' => '730',
        ]);

        $machine->refresh();
        $this->assertStringContainsString('Falha parcial em 1 documento(s)', $machine->last_error ?? '');
        $this->assertStringContainsString('FS / A2026 / 502', $machine->last_error ?? '');
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
}
