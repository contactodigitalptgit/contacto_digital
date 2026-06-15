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
        Schema::create('client_zonesoft_machines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zonesoft_application_id')->constrained('zonesoft_applications')->cascadeOnDelete();
            $table->string('zs_client_id', 64);
            $table->string('license', 64)->nullable();
            $table->unsignedInteger('store_id');
            $table->string('store_label')->nullable();
            $table->string('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'zs_client_id', 'store_id'], 'client_zonesoft_machine_unique');
            $table->index(['client_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_zonesoft_machines');
    }
};
