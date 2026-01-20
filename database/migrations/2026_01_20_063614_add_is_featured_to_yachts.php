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
        Schema::table('new_yachts', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('state');
        });

        Schema::table('used_yachts', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('new_yachts', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });

        Schema::table('used_yachts', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
