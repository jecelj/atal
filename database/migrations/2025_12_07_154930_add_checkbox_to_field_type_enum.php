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
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN field_type ENUM('text', 'textarea', 'richtext', 'number', 'date', 'select', 'checkbox', 'image', 'gallery', 'repeater', 'brand', 'model', 'file') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN field_type ENUM('text', 'textarea', 'richtext', 'number', 'date', 'select', 'image', 'gallery', 'repeater', 'brand', 'model', 'file') NOT NULL");
    }
};
