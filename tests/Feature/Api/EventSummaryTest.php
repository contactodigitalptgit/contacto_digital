<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRowAggregate;
use App\Models\EventReportTicketAggregate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile summary/top-stores API — reads the same PERF-401 aggregate tables
 * the web dashboard's fast path reads, so these numbers must always match
 * what a client sees on the web (see EventDashboardController::buildSummary()).
 */
class EventSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_the_authenticated_clients_active_events(): void
    {
        [$user, $client] = $this->makeClient();
        $ownEvent = $this->makeEvent($client, 'Evento Proprio');
        $this->makeEvent($client, 'Evento Inativo', active: false);

        [, $otherClient] = $this->makeClient();
        $this->makeEvent($otherClient, 'Evento de Outro Cliente');

        $response = $this->authenticated($user)->getJson('/api/events');

        $response->assertOk();
        $titles = collect($response->json('events'))->pluck('title');
        $this->assertEquals(['Evento Proprio'], $titles->all());
        $this->assertSame($ownEvent->id, $response->json('events.0.id'));
    }

    public function test_summary_matches_the_aggregate_tables_and_excludes_cm_zt(): void
    {
        [$user, $client] = $this->makeClient();
        $event = $this->makeEvent($client, 'Evento Resumo');
        $import = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $user->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'summary-test-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['machines_count' => 3],
            'imported_rows_count' => 3,
            'imported_at' => now(),
            'is_active' => true,
            'status' => 'completed',
        ]);

        $this->seedAggregateRow($event->id, 'Loja A', 'FS', 100.0);
        $this->seedAggregateRow($event->id, 'Loja B', 'FS', 50.0);
        $this->seedAggregateRow($event->id, 'Loja A', 'CM', 999.0); // excluded

        $this->seedTicket($event->id, 'FS');
        $this->seedTicket($event->id, 'FS');
        $this->seedTicket($event->id, 'CM'); // excluded

        $response = $this->authenticated($user)->getJson("/api/events/{$event->id}/summary");

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEqualsWithDelta(150.0, $summary['total_sales'], 0.0001);
        $this->assertSame(2, $summary['stores_count']);
        $this->assertSame(2, $summary['tickets_count']);
        $this->assertEqualsWithDelta(75.0, $summary['average_ticket'], 0.0001);
        $this->assertSame(3, $summary['machines_count']);
        $this->assertNotNull($summary['last_synced_at']);
        $this->assertSame($import->imported_at->toISOString(), $summary['last_synced_at']);
    }

    public function test_top_stores_orders_by_sales_descending(): void
    {
        [$user, $client] = $this->makeClient();
        $event = $this->makeEvent($client, 'Evento Lojas');

        $this->seedAggregateRow($event->id, 'Loja Pequena', 'FS', 10.0);
        $this->seedAggregateRow($event->id, 'Loja Grande', 'FS', 500.0);

        $response = $this->authenticated($user)->getJson("/api/events/{$event->id}/top-stores");

        $response->assertOk();
        $stores = $response->json('top_stores');
        $this->assertSame('Loja Grande', $stores[0]['store_name']);
        $this->assertSame('Loja Pequena', $stores[1]['store_name']);
    }

    public function test_dashboard_returns_all_mobile_sections_from_aggregates(): void
    {
        [$user, $client] = $this->makeClient();
        $event = $this->makeEvent($client, 'Evento Completo');

        $this->seedAggregateRow(
            $event->id,
            'Bar Central',
            'FS',
            80.0,
            productCode: 'C1',
            description: 'Cerveja',
            soldQuantity: 8,
            offeredQuantity: 2,
            hour: 20,
        );
        $this->seedAggregateRow(
            $event->id,
            'Bar VIP',
            'FS',
            40.0,
            productCode: 'A1',
            description: 'Água',
            soldQuantity: 4,
            hour: 21,
        );
        $this->seedAggregateRow(
            $event->id,
            'Bar Central',
            'CM',
            999.0,
            productCode: 'X1',
            description: 'Movimento excluído',
            hour: 22,
        );
        $this->seedTicket($event->id, 'FS', hour: 20);
        $this->seedTicket($event->id, 'FS', hour: 21);
        $this->seedTicket($event->id, 'CM', hour: 22);

        $response = $this->authenticated($user)
            ->getJson("/api/events/{$event->id}/dashboard");

        $response
            ->assertOk()
            ->assertJsonPath('event.title', 'Evento Completo')
            ->assertJsonPath('summary.total_sales', 120)
            ->assertJsonPath('summary.tickets_count', 2)
            ->assertJsonPath('top_stores.0.store_name', 'Bar Central')
            ->assertJsonPath('top_products.0.description', 'Cerveja')
            ->assertJsonPath('top_products.0.sold_quantity', 8)
            ->assertJsonPath('top_products.0.offered_quantity', 2)
            ->assertJsonPath('hourly_sales.0.hour', 20)
            ->assertJsonPath('hourly_sales.0.tickets_count', 1)
            ->assertJsonCount(2, 'hourly_sales')
            ->assertJsonCount(2, 'top_products');
    }

    public function test_returns_404_for_an_event_belonging_to_another_client(): void
    {
        [$user] = $this->makeClient();
        [, $otherClient] = $this->makeClient();
        $otherEvent = $this->makeEvent($otherClient, 'Evento de Outro Cliente');

        $this->authenticated($user)
            ->getJson("/api/events/{$otherEvent->id}/summary")
            ->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/events')->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Client}
     */
    private function makeClient(): array
    {
        $user = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente '.$user->id,
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);

        return [$user, $client];
    }

    private function makeEvent(Client $client, string $title, bool $active = true): Event
    {
        return Event::create([
            'client_id' => $client->id,
            'title' => $title,
            'event_date' => '2026-06-20 12:00:00',
            'report_starts_at' => '2026-06-20 00:00:00',
            'report_ends_at' => '2026-06-20 23:59:59',
            'is_active' => $active,
        ]);
    }

    private function seedAggregateRow(
        int $eventId,
        string $storeName,
        string $docType,
        float $total,
        string $productCode = 'P1',
        string $description = 'Produto',
        float $soldQuantity = 1,
        float $offeredQuantity = 0,
        int $hour = 12,
    ): void {
        EventReportRowAggregate::create([
            'event_id' => $eventId,
            'sale_date' => '2026-06-20',
            'sale_calendar_date' => '2026-06-20',
            'sale_hour' => $hour,
            'store_code' => $storeName,
            'store_name' => $storeName,
            'doc_type' => $docType,
            'product_code' => $productCode,
            'description' => $description,
            'rows_count' => 1,
            'quantity_total' => $soldQuantity + $offeredQuantity,
            'value_total' => $total,
            'discount_total' => 0,
            'total_sum' => $total,
            'offered_quantity_total' => $offeredQuantity,
            'sold_quantity_total' => $soldQuantity,
        ]);
    }

    private function seedTicket(int $eventId, string $docType, int $hour = 12): void
    {
        static $documentNumber = 0;
        $documentNumber++;

        EventReportTicketAggregate::create([
            'event_id' => $eventId,
            'sale_date' => '2026-06-20',
            'sale_calendar_date' => '2026-06-20',
            'sale_hour' => $hour,
            'store_code' => 'Loja A',
            'store_name' => 'Loja A',
            'doc_type' => $docType,
            'document_series' => 'A2026',
            'document_number' => (string) $documentNumber,
        ]);
    }

    private function authenticated(User $user): static
    {
        $token = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
