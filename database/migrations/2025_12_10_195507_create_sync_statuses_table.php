<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_site_id')->constrained()->onDelete('cascade');
            $table->string('model_type'); // NewYacht, UsedYacht, News
            $table->unsignedBigInteger('model_id');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('content_hash')->nullable();
            $table->enum('status', ['synced', 'failed', 'pending'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['sync_site_id', 'model_type', 'model_id'], 'sync_status_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_statuses');
    }
};
