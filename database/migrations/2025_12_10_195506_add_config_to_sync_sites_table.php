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
        Schema::table('sync_sites', function (Blueprint $table) {
            $table->string('default_language')->default('sl')->after('api_key');
            $table->json('supported_languages')->nullable()->after('default_language');
            $table->boolean('sync_all_brands')->default(true)->after('supported_languages');
            $table->json('brand_restrictions')->nullable()->after('sync_all_brands');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_sites', function (Blueprint $table) {
            $table->dropColumn(['default_language', 'supported_languages', 'sync_all_brands', 'brand_restrictions']);
        });
    }
};
