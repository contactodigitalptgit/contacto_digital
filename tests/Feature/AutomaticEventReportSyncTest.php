<?php

namespace Tests\Feature;

use App\Jobs\SyncEventReportJob;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\EventReportAutoSyncService;
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

    public function test_scheduler_runs_a_final_sync_at_the_required_event_end_time(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 22:00:01');

        try {
            $event = $this->makeConfiguredEvent();
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutputToContain("started for event #{$event->id}")
                ->assertSuccessful();

            Bus::assertDispatched(SyncEventReportJob::class);
            $this->assertDatabaseCount('event_report_imports', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_caps_the_next_cycle_at_event_end(): void
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

            $status = app(EventReportAutoSyncService::class)->status($event->fresh());

            $this->assertSame('scheduled', $status['state']);
            $this->assertSame(
                '2026-07-18 22:00:00',
                CarbonImmutable::parse($status['next_sync_at'])->format('Y-m-d H:i:s'),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_retries_a_failed_final_sync_inside_the_grace_period(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 22:10:00');

        try {
            $event = $this->makeConfiguredEvent();
            $this->makeFailedImport($event, CarbonImmutable::parse('2026-07-18 22:00:00'));
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutputToContain("started for event #{$event->id}")
                ->assertSuccessful();

            Bus::assertDispatched(SyncEventReportJob::class);
            $this->assertDatabaseCount('event_report_imports', 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_stops_after_a_successful_final_sync(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 22:10:00');

        try {
            $event = $this->makeConfiguredEvent();
            $this->makeCompletedImport($event, CarbonImmutable::parse('2026-07-18 22:00:30'));
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutput('No event report synchronization is due.')
                ->assertSuccessful();

            Bus::assertNothingDispatched();
            $this->assertSame('finished', app(EventReportAutoSyncService::class)->status($event->fresh())['state']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_repeats_a_sync_that_started_before_event_end(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 22:11:00');

        try {
            $event = $this->makeConfiguredEvent();
            $this->makeCompletedImport(
                $event,
                CarbonImmutable::parse('2026-07-18 22:01:00'),
                CarbonImmutable::parse('2026-07-18 21:59:30'),
            );
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutputToContain("started for event #{$event->id}")
                ->assertSuccessful();

            Bus::assertDispatched(SyncEventReportJob::class);
            $this->assertDatabaseCount('event_report_imports', 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scheduler_stops_failed_final_retries_after_the_grace_period(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 23:00:01');

        try {
            $event = $this->makeConfiguredEvent();
            $this->makeFailedImport($event, CarbonImmutable::parse('2026-07-18 22:50:00'));
            Bus::fake();

            $this->artisan('events:sync-due-reports')
                ->expectsOutput('No event report synchronization is due.')
                ->assertSuccessful();

            Bus::assertNothingDispatched();
            $this->assertSame('finished', app(EventReportAutoSyncService::class)->status($event->fresh())['state']);
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
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'AUTOMATIC-CLIENT-ID',
            'license' => 'AUTOMATIC-LICENSE',
            'store_id' => 1,
            'store_label' => 'Device Automatico',
            'is_active' => true,
        ]);

        return $event;
    }

    private function makeCompletedImport(
        Event $event,
        CarbonInterface $importedAt,
        ?CarbonInterface $startedAt = null,
    ): EventReportImport {
        $import = EventReportImport::create([
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

        if ($startedAt) {
            $import->timestamps = false;
            $import->forceFill([
                'created_at' => $startedAt,
                'updated_at' => $importedAt,
            ])->saveQuietly();
        }

        return $import;
    }

    private function makeFailedImport(Event $event, CarbonInterface $attemptedAt): EventReportImport
    {
        $import = EventReportImport::create([
            'event_id' => $event->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'failed-automatic-'.$event->id.'-'.$attemptedAt->timestamp),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'error' => 'Temporary failure'],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'failed',
        ]);

        $import->timestamps = false;
        $import->forceFill([
            'created_at' => $attemptedAt,
            'updated_at' => $attemptedAt,
        ])->saveQuietly();

        return $import;
    }
}
