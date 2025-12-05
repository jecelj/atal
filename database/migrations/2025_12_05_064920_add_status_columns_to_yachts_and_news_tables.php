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
        Schema::table('yachts', function (Blueprint $table) {
            $table->boolean('img_opt_status')->nullable()->after('custom_fields');
            $table->boolean('translation_status')->nullable()->after('img_opt_status');
        });

        Schema::table('news', function (Blueprint $table) {
            $table->boolean('img_opt_status')->nullable()->after('custom_fields');
            $table->boolean('translation_status')->nullable()->after('img_opt_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('yachts', function (Blueprint $table) {
            $table->dropColumn(['img_opt_status', 'translation_status']);
        });

        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn(['img_opt_status', 'translation_status']);
        });
    }
};
