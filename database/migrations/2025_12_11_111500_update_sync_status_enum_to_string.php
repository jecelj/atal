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
        Schema::table('sync_statuses', function (Blueprint $table) {
            // Change enum to string to allow 'skipped' and future statuses
            $table->string('status')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverting not easily possible if 'skipped' values exist, 
        // but technically we could revert to enum if we cleaned data.
        // For safety, we'll leave it as string or try to convert back.
    }
};
