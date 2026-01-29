<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN entity_type ENUM('new_yacht', 'used_yacht', 'news', 'charter_yacht')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // CAUTION: Reverting this might cause data loss for 'charter_yacht' entries
        DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN entity_type ENUM('new_yacht', 'used_yacht', 'news')");
    }
};
