<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Atal SK"
            $table->string('url'); // e.g., "https://atal.sk/wp-json/atal-sync/v1/import"
            $table->string('api_key')->nullable(); // Optional API key for authentication
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('last_sync_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_sites');
    }
};
