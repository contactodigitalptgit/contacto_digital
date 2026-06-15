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
        Schema::create('zonesoft_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('base_url')->default('https://api.zonesoft.org/v3');
            $table->string('app_key');
            $table->text('app_secret');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zonesoft_applications');
    }
};
