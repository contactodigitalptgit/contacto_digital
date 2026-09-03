<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FESNIMA_CLIENT_ID = 6;

    private const FESNIMA_EVENT_ID = 8;

    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        // PERF-501: this ran once already against the real, already-populated
        // production SQLite database (that's what the guard below is
        // checking against). Bootstrapping a brand-new destination schema
        // from scratch (e.g. `migrate --database=pgsql` before the data
        // copy step) replays every migration in history against an EMPTY
        // database — there is nothing to guard or clean up yet, and the
        // eventual copy of the (already-cleaned) SQLite data will produce
        // the same "only Fesnima" result on its own. Only skip when
        // genuinely empty; a populated destination that still doesn't
        // match the guard is exactly the case this must keep aborting on.
        if ((int) DB::table('clients')->count() === 0 && (int) DB::table('events')->count() === 0) {
            return;
        }

        $fesnima = DB::table('clients')->find(self::FESNIMA_CLIENT_ID);
        $event = DB::table('events')->find(self::FESNIMA_EVENT_ID);

        if (
            ! $fesnima
            || mb_strtolower(trim((string) $fesnima->name)) !== 'fesnima'
            || ! $event
            || (int) $event->client_id !== self::FESNIMA_CLIENT_ID
            || trim((string) $event->title) !== 'Festival de Marisco 2026'
        ) {
            throw new \RuntimeException('Fesnima production guard did not match; cleanup aborted.');
        }

        $clientIds = DB::table('clients')
            ->where('id', '!=', self::FESNIMA_CLIENT_ID)
            ->pluck('id');
        $eventIds = DB::table('events')
            ->whereIn('client_id', $clientIds)
            ->pluck('id');
        $machineIds = DB::table('client_zonesoft_machines')
            ->whereIn('client_id', $clientIds)
            ->pluck('id');
        $keptUserIds = DB::table('users')
            ->where('role', 'admin')
            ->pluck('id')
            ->push((int) $fesnima->user_id)
            ->unique()
            ->values();
        $keptEmails = DB::table('users')
            ->whereIn('id', $keptUserIds)
            ->pluck('email');

        DB::transaction(function () use (
            $clientIds,
            $eventIds,
            $machineIds,
            $keptUserIds,
            $keptEmails,
        ): void {
            DB::table('event_zonesoft_machines')
                ->whereIn('event_id', $eventIds)
                ->orWhereIn('client_zonesoft_machine_id', $machineIds)
                ->delete();
            DB::table('event_report_payment_documents')->whereIn('event_id', $eventIds)->delete();
            DB::table('event_report_rows')->whereIn('event_id', $eventIds)->delete();
            DB::table('event_report_imports')->whereIn('event_id', $eventIds)->delete();
            DB::table('events')->whereIn('id', $eventIds)->delete();
            DB::table('client_zonesoft_machines')->whereIn('id', $machineIds)->delete();
            DB::table('clients')->whereIn('id', $clientIds)->delete();

            DB::table('event_report_imports')
                ->whereNotNull('uploaded_by_user_id')
                ->whereNotIn('uploaded_by_user_id', $keptUserIds)
                ->update(['uploaded_by_user_id' => null]);
            DB::table('users')->whereNotIn('id', $keptUserIds)->delete();
            DB::table('sessions')
                ->whereNull('user_id')
                ->orWhereNotIn('user_id', $keptUserIds)
                ->delete();
            DB::table('password_reset_tokens')->whereNotIn('email', $keptEmails)->delete();

            $keptApplicationIds = DB::table('client_zonesoft_machines')
                ->where('client_id', self::FESNIMA_CLIENT_ID)
                ->pluck('zonesoft_application_id')
                ->unique();
            DB::table('zonesoft_applications')->whereNotIn('id', $keptApplicationIds)->delete();

            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();
            DB::table('job_batches')->delete();
            DB::table('cache')->delete();
            DB::table('cache_locks')->delete();
        });
    }

    public function down(): void
    {
        // The pre-deploy database snapshot is the only supported rollback.
    }
};
