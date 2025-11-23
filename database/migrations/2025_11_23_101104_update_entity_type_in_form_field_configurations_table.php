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
        // We need to use raw SQL to modify ENUM column in MySQL/MariaDB
        DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN entity_type ENUM('new_yacht', 'used_yacht', 'news')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN entity_type ENUM('new_yacht', 'used_yacht')");
    }
};
