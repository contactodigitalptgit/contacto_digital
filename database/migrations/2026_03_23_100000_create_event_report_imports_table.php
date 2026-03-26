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
        Schema::create('event_report_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('import_strategy', 16);
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->string('file_hash', 64);
            $table->json('headers')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('imported_rows_count')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('completed');
            $table->timestamps();

            $table->index(['event_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_report_imports');
    }
};
