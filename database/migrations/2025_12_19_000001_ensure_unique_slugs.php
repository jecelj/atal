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
        // YACHTS
        Schema::table('yachts', function (Blueprint $table) {
            // Check if index exists usually requires dropping and re-adding or just adding if not exists
            // Laravel Schema builder doesn't have hasIndex method easily available in all drivers, 
            // but we can try-catch or use raw SQL. 
            // Better: Drop allow duplicates if needed? No, we want to enforce unique.

            // We assume the user has cleaned duplicates if any.
            // If we run this and duplicates exist, it will fail.
            $table->unique('slug');
        });

        // NEWS
        Schema::table('news', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('yachts', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });

        Schema::table('news', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });
    }
};
