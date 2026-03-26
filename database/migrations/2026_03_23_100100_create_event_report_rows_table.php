<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_report_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_report_import_id')->constrained()->cascadeOnDelete();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row_number');
            $table->string('store_code')->nullable();
            $table->string('store_name')->nullable();
            $table->date('sale_date')->nullable();
            $table->dateTime('sale_datetime')->nullable();
            $table->string('doc_type')->nullable();
            $table->string('document_series')->nullable();
            $table->string('document_number')->nullable();
            $table->decimal('value', 14, 4)->nullable();
            $table->decimal('total', 14, 4)->nullable();
            $table->decimal('discount', 14, 4)->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('product_code')->nullable();
            $table->string('description')->nullable();
            $table->json('raw_row')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'sale_date']);
            $table->index(['event_id', 'product_code']);
            $table->index(['event_id', 'store_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_report_rows');
    }
};
