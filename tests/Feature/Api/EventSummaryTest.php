<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportPaymentDocument;
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

    public function test_mobile_sections_support_multiple_zones_and_keep_metrics_consistent(): void
    {
        [$user, $client] = $this->makeClient();
        $event = $this->makeEvent($client, 'Evento Filtros');

        $this->seedAggregateRow($event->id, 'Bar 1 - POS A', 'FS', 100, soldQuantity: 10, offeredQuantity: 2, hour: 20);
        $this->seedAggregateRow($event->id, 'Bar 2 - POS B', 'FS', 60, productCode: 'P2', description: 'Água', soldQuantity: 6, hour: 21);
        $this->seedAggregateRow($event->id, 'Bilheteira', 'FS', 500, productCode: 'P3', description: 'Bilhete', soldQuantity: 5, hour: 22);
        $this->seedTicket($event->id, 'FS', 20, 'Bar 1 - POS A');
        $this->seedTicket($event->id, 'FS', 21, 'Bar 2 - POS B');
        $this->seedTicket($event->id, 'FS', 22, 'Bilheteira');

        $query = http_build_query(['bar_groups' => ['Bar 1', 'Bar 2']]);

        $this->authenticated($user)
            ->getJson("/api/events/{$event->id}/zones?{$query}")
            ->assertOk()
            ->assertJsonPath('summary.total_sales', 160)
            ->assertJsonPath('summary.zones_count', 2)
            ->assertJsonPath('summary.tickets_count', 2)
            ->assertJsonCount(2, 'items');

        $this->authenticated($user)
            ->getJson("/api/events/{$event->id}/products?{$query}")
            ->assertOk()
            ->assertJsonPath('summary.sold_quantity', 16)
            ->assertJsonPath('summary.offered_quantity', 2)
            ->assertJsonPath('summary.served_quantity', 18)
            ->assertJsonCount(2, 'items');

        $this->authenticated($user)
            ->getJson("/api/events/{$event->id}/performance?{$query}")
            ->assertOk()
            ->assertJsonPath('summary.best_product.description', 'Produto')
            ->assertJsonPath('summary.peak_hour.hour', 20);

        $this->authenticated($user)
            ->getJson("/api/events/{$event->id}/dashboard?{$query}")
            ->assertOk()
            ->assertJsonPath('summary.total_sales', 160)
            ->assertJsonPath('summary.tickets_count', 2)
            ->assertJsonCount(2, 'top_stores');
    }

    public function test_payment_endpoint_separates_sales_topups_and_reconciliation(): void
    {
        [$user, $client] = $this->makeClient();
        $event = $this->makeEvent($client, 'Evento Pagamentos');
        $import = $this->makeImport($event, $user, machines: 1);

        $this->seedAggregateRow($event->id, 'Bar 1 - POS A', 'FS', 100);
        $this->seedPayment($event, $import, 'Bar 1 - POS A', 'FS', '3', 70, '1');
        $this->seedPayment($event, $import, 'Bar 1 - POS A', 'FS', '1', 30, '1');
        $this->seedPayment($event, $import, 'Top Up 1', 'ZT', '10', 200, '2');

        $this->authenticated($user)
            ->getJson("/api/events/{$event->id}/payments")
            ->assertOk()
            ->assertJsonPath('summary.multibanco', 70)
            ->assertJsonPath('summary.cash', 30)
            ->assertJsonPath('summary.top_up_loaded', 200)
            ->assertJsonPath('summary.sales_total', 100)
            ->assertJsonPath('summary.total_with_zt', 300)
            ->assertJsonPath('reconciliation.totals.difference', 0)
            ->assertJsonPath('reconciliation.items.0.store_name', 'Bar 1 - POS A');
    }

    public function test_filters_configuration_and_comparison_are_available_to_mobile(): void
    {
        [$user, $client] = $this->makeClient();
        $previous = $this->makeEvent($client, 'Evento Anterior');
        $previous->update(['event_date' => '2026-05-20 12:00:00']);
        $this->makeImport($previous, $user, machines: 2);
        $this->seedAggregateRow($previous->id, 'Bar 1', 'FS', 100);
        $this->seedTicket($previous->id, 'FS', 12, 'Bar 1');

        $current = $this->makeEvent($client, 'Evento Atual');
        $this->makeImport($current, $user, machines: 4);
        $this->seedAggregateRow($current->id, 'Bar 1 - POS A', 'FS', 150, hour: 20);
        $this->seedAggregateRow($current->id, 'Bar 2 - POS B', 'FS', 50, productCode: 'P2', description: 'Água', hour: 21);
        $this->seedTicket($current->id, 'FS', 20, 'Bar 1 - POS A');
        $this->seedTicket($current->id, 'FS', 21, 'Bar 2 - POS B');

        $this->authenticated($user)
            ->getJson("/api/events/{$current->id}/filters")
            ->assertOk()
            ->assertJsonCount(2, 'filters.zones')
            ->assertJsonCount(2, 'filters.stores')
            ->assertJsonPath('filters.date_bounds.from', '2026-06-20');

        $this->authenticated($user)
            ->getJson("/api/events/{$current->id}/configuration")
            ->assertOk()
            ->assertJsonPath('configuration.preset', 'complete');

        $this->authenticated($user)
            ->getJson("/api/events/{$current->id}/comparison")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('current.total_sales', 200)
            ->assertJsonPath('previous.total_sales', 100)
            ->assertJsonPath('total_variation', 100);
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

    private function makeImport(Event $event, User $user, int $machines): EventReportImport
    {
        return EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $user->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'mobile-import-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['machines_count' => $machines],
            'imported_rows_count' => 1,
            'imported_at' => now(),
            'is_active' => true,
            'status' => 'completed',
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

    private function seedTicket(int $eventId, string $docType, int $hour = 12, string $storeName = 'Loja A'): void
    {
        static $documentNumber = 0;
        $documentNumber++;

        EventReportTicketAggregate::create([
            'event_id' => $eventId,
            'sale_date' => '2026-06-20',
            'sale_calendar_date' => '2026-06-20',
            'sale_hour' => $hour,
            'store_code' => $storeName,
            'store_name' => $storeName,
            'doc_type' => $docType,
            'document_series' => 'A2026',
            'document_number' => (string) $documentNumber,
        ]);
    }

    private function seedPayment(
        Event $event,
        EventReportImport $import,
        string $storeName,
        string $docType,
        string $paymentCode,
        float $total,
        string $documentNumber,
    ): void {
        EventReportPaymentDocument::create([
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'store_code' => $storeName,
            'store_name' => $storeName,
            'sale_date' => '2026-06-20',
            'sale_datetime' => '2026-06-20 12:00:00',
            'doc_type' => $docType,
            'document_series' => 'A2026',
            'document_number' => $documentNumber,
            'payment_code' => $paymentCode,
            'total' => $total,
            'dedupe_key' => implode('|', [$storeName, $docType, $paymentCode, $documentNumber]),
        ]);
    }

    private function authenticated(User $user): static
    {
        $token = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
