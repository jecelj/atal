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
        DB::statement("ALTER TABLE yachts MODIFY COLUMN type ENUM('new', 'used', 'charter') DEFAULT 'new'");
    }

    public function down(): void
    {
        // Reverting enum modification is tricky if data exists, but we'll define the reverse
        DB::statement("ALTER TABLE yachts MODIFY COLUMN type ENUM('new', 'used') DEFAULT 'new'");
    }
};
