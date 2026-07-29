<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sync_statuses', function (Blueprint $table) {
            $table->unsignedTinyInteger('sync_version')->default(1)->after('content_hash');
        });

        Schema::table('sync_sites', function (Blueprint $table) {
            $table->string('last_config_hash')->nullable()->after('last_sync_result');
        });

        Schema::create('wordpress_sync_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_site_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('source_id');
            $table->string('action');
            $table->string('state')->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->json('payload')->nullable();
            $table->unsignedInteger('media_cursor')->default(0);
            $table->string('content_hash')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['sync_site_id', 'model_type', 'model_id'], 'wordpress_sync_outbox_item_unique');
            $table->index(['sync_site_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_sync_outbox');

        Schema::table('sync_sites', function (Blueprint $table) {
            $table->dropColumn('last_config_hash');
        });

        Schema::table('sync_statuses', function (Blueprint $table) {
            $table->dropColumn('sync_version');
        });
    }
};
