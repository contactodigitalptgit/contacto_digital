<?php

namespace Tests\Feature;

use App\Jobs\SyncEventReportJob;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutomaticEventReportSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_starts_one_due_event_and_dispatches_its_sync(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 20:00:00');

        try {
            $event = $this->makeConfiguredEvent();
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutputToContain("started for event #{$event->id}")
                ->assertSuccessful();

            $sync = EventReportImport::query()->sole();

            $this->assertSame('processing', $sync->status);
            $this->assertNull($sync->uploaded_by_user_id);
            Bus::assertDispatched(
                SyncEventReportJob::class,
                fn (SyncEventReportJob $job): bool => $job->importId === $sync->id
                    && $job->eventId === $event->id,
            );

            $this->artisan('events:sync-due-reports')
                ->expectsOutput('A synchronization is already in progress.')
                ->assertSuccessful();

            $this->assertSame(1, EventReportImport::query()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_waits_fifteen_minutes_after_the_latest_attempt(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 20:00:00');

        try {
            $event = $this->makeConfiguredEvent();
            $this->makeCompletedImport($event, now()->subMinutes(5));
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutput('No event report synchronization is due.')
                ->assertSuccessful();

            Bus::assertNothingDispatched();
            $this->assertSame(1, EventReportImport::query()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_stops_after_the_required_event_end_time(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 22:00:01');

        try {
            $this->makeConfiguredEvent();
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutput('No event report synchronization is due.')
                ->assertSuccessful();

            Bus::assertNothingDispatched();
            $this->assertDatabaseCount('event_report_imports', 0);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_does_not_plan_a_cycle_that_would_start_after_event_end(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 21:55:00');

        try {
            $event = $this->makeConfiguredEvent();
            $this->makeCompletedImport($event, now()->subMinutes(5));
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutput('No event report synchronization is due.')
                ->assertSuccessful();

            Bus::assertNothingDispatched();
            $this->assertSame(1, EventReportImport::query()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_event_end_time_is_required_when_creating_an_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clientUser = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente sem fim',
            'address' => 'Rua sem fim',
            'phone' => '+351 930000010',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.events.store'), [
                'client_id' => $client->id,
                'title' => 'Evento sem fim',
                'event_date' => now()->addDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors(['report_ends_at']);

        $this->assertDatabaseCount('events', 0);
    }

    private function makeConfiguredEvent(): Event
    {
        $clientUser = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Automatico',
            'address' => 'Rua Automatica',
            'phone' => '+351 930000011',
            'is_active' => true,
        ]);
        $application = ZoneSoftApplication::create([
            'name' => 'ZoneSoft Principal',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'automatic-app-key',
            'app_secret' => 'automatic-secret',
            'is_active' => true,
        ]);
        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Automatico',
            'event_date' => '2026-07-18 18:00:00',
            'report_starts_at' => '2026-07-18 18:00:00',
            'report_ends_at' => '2026-07-18 22:00:00',
            'is_active' => true,
        ]);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'AUTOMATIC-CLIENT-ID',
            'license' => 'AUTOMATIC-LICENSE',
            'store_id' => 1,
            'store_label' => 'Device Automatico',
            'is_active' => true,
        ]);

        return $event;
    }

    private function makeCompletedImport(Event $event, CarbonInterface $importedAt): EventReportImport
    {
        return EventReportImport::create([
            'event_id' => $event->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'automatic-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 1],
            'imported_rows_count' => 0,
            'imported_at' => $importedAt,
            'is_active' => true,
            'status' => 'completed',
        ]);
    }
}
