<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'name'], 'event_zones_event_name_unique');
            $table->index(['event_id', 'archived_at', 'sort_order'], 'event_zones_active_order_index');
        });

        Schema::create('event_zone_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_zone_id')->constrained('event_zones')->restrictOnDelete();
            $table->foreignId('machine_id')->constrained('client_zonesoft_machines')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->unique(
                ['event_id', 'machine_id', 'starts_at'],
                'event_zone_assignments_machine_start_unique',
            );
            $table->index(
                ['event_id', 'machine_id', 'starts_at', 'ends_at'],
                'event_zone_assignments_timeline_index',
            );
            $table->index(
                ['event_id', 'event_zone_id', 'starts_at'],
                'event_zone_assignments_zone_start_index',
            );
        });

        Schema::table('event_report_rows', function (Blueprint $table): void {
            $table->foreignId('event_zone_id')
                ->nullable()
                ->after('machine_id')
                ->constrained('event_zones')
                ->nullOnDelete();
            $table->index(['event_id', 'event_zone_id'], 'event_report_rows_event_zone_index');
        });

        Schema::table('event_report_payment_documents', function (Blueprint $table): void {
            $table->foreignId('event_zone_id')
                ->nullable()
                ->after('machine_id')
                ->constrained('event_zones')
                ->nullOnDelete();
            $table->index(['event_id', 'event_zone_id'], 'event_payment_documents_event_zone_index');
        });

        Schema::table('event_report_row_aggregates', function (Blueprint $table): void {
            $table->dropUnique('event_report_row_aggregates_grain_unique');
            $table->foreignId('event_zone_id')
                ->nullable()
                ->after('machine_id')
                ->constrained('event_zones')
                ->nullOnDelete();
            $table->unique([
                'event_id', 'machine_id', 'event_zone_id', 'sale_date', 'sale_calendar_date', 'sale_hour',
                'store_code', 'store_name', 'doc_type', 'product_code', 'description',
            ], 'event_report_row_aggregates_grain_unique');
            $table->index(['event_id', 'event_zone_id'], 'event_row_aggregates_event_zone_index');
        });

        Schema::table('event_report_ticket_aggregates', function (Blueprint $table): void {
            $table->dropUnique('event_report_ticket_aggregates_document_unique');
            $table->foreignId('event_zone_id')
                ->nullable()
                ->after('machine_id')
                ->constrained('event_zones')
                ->nullOnDelete();
            $table->unique([
                'event_id', 'machine_id', 'event_zone_id', 'store_code', 'store_name',
                'doc_type', 'document_series', 'document_number',
            ], 'event_report_ticket_aggregates_document_unique');
            $table->index(['event_id', 'event_zone_id'], 'event_ticket_aggregates_event_zone_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_report_ticket_aggregates', function (Blueprint $table): void {
            $table->dropIndex('event_ticket_aggregates_event_zone_index');
            $table->dropUnique('event_report_ticket_aggregates_document_unique');
            $table->dropConstrainedForeignId('event_zone_id');
            $table->unique([
                'event_id', 'machine_id', 'store_code', 'store_name',
                'doc_type', 'document_series', 'document_number',
            ], 'event_report_ticket_aggregates_document_unique');
        });

        Schema::table('event_report_row_aggregates', function (Blueprint $table): void {
            $table->dropIndex('event_row_aggregates_event_zone_index');
            $table->dropUnique('event_report_row_aggregates_grain_unique');
            $table->dropConstrainedForeignId('event_zone_id');
            $table->unique([
                'event_id', 'machine_id', 'sale_date', 'sale_calendar_date', 'sale_hour',
                'store_code', 'store_name', 'doc_type', 'product_code', 'description',
            ], 'event_report_row_aggregates_grain_unique');
        });

        Schema::table('event_report_payment_documents', function (Blueprint $table): void {
            $table->dropIndex('event_payment_documents_event_zone_index');
            $table->dropConstrainedForeignId('event_zone_id');
        });

        Schema::table('event_report_rows', function (Blueprint $table): void {
            $table->dropIndex('event_report_rows_event_zone_index');
            $table->dropConstrainedForeignId('event_zone_id');
        });

        Schema::dropIfExists('event_zone_assignments');
        Schema::dropIfExists('event_zones');
    }
};
