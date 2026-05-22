<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sync_sites', function (Blueprint $table) {
            $table->boolean('sync_new_yachts')->default(true)->after('supported_languages');
            $table->boolean('sync_used_yachts')->default(true)->after('sync_new_yachts');
            $table->boolean('sync_charter_yachts')->default(true)->after('sync_used_yachts');
        });
    }

    public function down(): void
    {
        Schema::table('sync_sites', function (Blueprint $table) {
            $table->dropColumn([
                'sync_new_yachts',
                'sync_used_yachts',
                'sync_charter_yachts',
            ]);
        });
    }
};
