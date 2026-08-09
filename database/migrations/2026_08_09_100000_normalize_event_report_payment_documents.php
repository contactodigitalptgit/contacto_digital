<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_report_payment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_report_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('client_zonesoft_machines')->nullOnDelete();
            $table->string('machine_client_id')->nullable();
            $table->string('store_code')->nullable();
            $table->string('store_name')->nullable();
            $table->date('sale_date')->nullable();
            $table->dateTime('sale_datetime')->nullable();
            $table->string('doc_type')->nullable();
            $table->string('document_series')->nullable();
            $table->string('document_number')->nullable();
            $table->string('payment_reference')->nullable();
            $table->boolean('paid')->nullable();
            $table->decimal('document_total', 14, 4)->nullable();
            $table->string('payment_key')->nullable();
            $table->string('payment_code')->nullable();
            $table->string('payment_document_type')->nullable();
            $table->string('payment_document_series')->nullable();
            $table->string('payment_document_number')->nullable();
            $table->string('payment_card_number')->nullable();
            $table->decimal('total', 14, 4)->nullable();
            $table->boolean('is_unallocated')->default(false);
            $table->string('dedupe_key');
            $table->timestamps();

            $table->unique(
                ['event_report_import_id', 'dedupe_key'],
                'event_payment_documents_import_dedupe_unique',
            );
            $table->index(
                ['event_report_import_id', 'sale_date'],
                'event_payment_documents_import_date_index',
            );
            $table->index(
                ['event_id', 'store_code'],
                'event_payment_documents_event_store_index',
            );
        });

        DB::table('event_report_imports')
            ->where('is_active', true)
            ->where('status', 'completed')
            ->select(['id', 'event_id', 'summary', 'imported_at', 'created_at'])
            ->orderBy('id')
            ->chunkById(1, function ($imports): void {
                foreach ($imports as $import) {
                    $summary = json_decode((string) $import->summary, true);

                    if (! is_array($summary)) {
                        continue;
                    }

                    $documents = array_values(array_filter(
                        is_array($summary['payment_documents'] ?? null)
                            ? $summary['payment_documents']
                            : [],
                        'is_array',
                    ));
                    $timestamp = $import->imported_at ?? $import->created_at ?? now();

                    DB::transaction(function () use ($import, $documents, $summary, $timestamp): void {
                        foreach (array_chunk($documents, 250) as $chunk) {
                            DB::table('event_report_payment_documents')->insertOrIgnore(
                                array_map(
                                    fn (array $document): array => $this->mapDocument(
                                        (int) $import->event_id,
                                        (int) $import->id,
                                        $document,
                                        $timestamp,
                                    ),
                                    $chunk,
                                ),
                            );
                        }

                        unset($summary['payment_documents']);
                        $summary['payment_documents_count'] = count($documents);

                        DB::table('event_report_imports')
                            ->where('id', $import->id)
                            ->update([
                                'summary' => json_encode(
                                    $summary,
                                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                                ),
                            ]);
                    });

                    unset($documents, $summary);
                    gc_collect_cycles();
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('event_report_imports')
            ->whereIn('id', DB::table('event_report_payment_documents')->select('event_report_import_id')->distinct())
            ->select(['id', 'summary'])
            ->orderBy('id')
            ->chunkById(1, function ($imports): void {
                foreach ($imports as $import) {
                    $summary = json_decode((string) $import->summary, true);

                    if (! is_array($summary)) {
                        $summary = [];
                    }

                    $summary['payment_documents'] = DB::table('event_report_payment_documents')
                        ->where('event_report_import_id', $import->id)
                        ->orderBy('id')
                        ->get()
                        ->map(fn (object $document): array => $this->restoreDocument($document))
                        ->all();
                    unset($summary['payment_documents_count']);

                    DB::table('event_report_imports')
                        ->where('id', $import->id)
                        ->update([
                            'summary' => json_encode(
                                $summary,
                                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                            ),
                        ]);
                }
            });

        Schema::dropIfExists('event_report_payment_documents');
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function mapDocument(
        int $eventId,
        int $importId,
        array $document,
        mixed $timestamp,
    ): array {
        return [
            'event_id' => $eventId,
            'event_report_import_id' => $importId,
            'machine_id' => $document['machine_id'] ?? null,
            'machine_client_id' => $document['machine_client_id'] ?? null,
            'store_code' => $document['store_code'] ?? null,
            'store_name' => $document['store_name'] ?? null,
            'sale_date' => $document['sale_date'] ?? null,
            'sale_datetime' => $document['sale_datetime'] ?? null,
            'doc_type' => $document['doc_type'] ?? null,
            'document_series' => $document['document_series'] ?? null,
            'document_number' => $document['document_number'] ?? null,
            'payment_reference' => $document['payment_reference'] ?? null,
            'paid' => $document['paid'] ?? null,
            'document_total' => $document['document_total'] ?? null,
            'payment_key' => $document['payment_key'] ?? null,
            'payment_code' => $document['payment_code'] ?? null,
            'payment_document_type' => $document['payment_document_type'] ?? null,
            'payment_document_series' => $document['payment_document_series'] ?? null,
            'payment_document_number' => $document['payment_document_number'] ?? null,
            'payment_card_number' => $document['payment_card_number'] ?? null,
            'total' => $document['total'] ?? null,
            'is_unallocated' => (bool) ($document['is_unallocated'] ?? false),
            'dedupe_key' => $this->dedupeKey($document),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreDocument(object $document): array
    {
        return [
            'machine_id' => $document->machine_id,
            'machine_client_id' => $document->machine_client_id,
            'store_code' => $document->store_code,
            'store_name' => $document->store_name,
            'sale_date' => $document->sale_date,
            'sale_datetime' => $document->sale_datetime,
            'doc_type' => $document->doc_type,
            'document_series' => $document->document_series,
            'document_number' => $document->document_number,
            'payment_reference' => $document->payment_reference,
            'paid' => $document->paid === null ? null : (bool) $document->paid,
            'document_total' => $document->document_total,
            'payment_key' => $document->payment_key,
            'payment_code' => $document->payment_code,
            'payment_document_type' => $document->payment_document_type,
            'payment_document_series' => $document->payment_document_series,
            'payment_document_number' => $document->payment_document_number,
            'payment_card_number' => $document->payment_card_number,
            'total' => $document->total,
            'is_unallocated' => (bool) $document->is_unallocated,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function dedupeKey(array $document): string
    {
        return hash('sha256', implode('|', [
            $document['machine_client_id'] ?? $document['store_code'] ?? '',
            $document['store_code'] ?? '',
            $document['doc_type'] ?? '',
            $document['document_series'] ?? '',
            $document['document_number'] ?? '',
            $document['payment_key'] ?? 'header',
            $document['payment_code'] ?? '',
        ]));
    }
};
