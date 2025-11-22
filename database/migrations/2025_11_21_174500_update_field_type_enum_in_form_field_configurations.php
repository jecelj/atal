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
        // We use raw SQL because changing enum values with Schema builder can be problematic
        // and might not be supported by all drivers or require doctrine/dbal
        DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN field_type ENUM('text', 'textarea', 'select', 'image', 'gallery', 'brand', 'model', 'number', 'date', 'richtext', 'file') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum values (warning: this might fail if there are 'file' entries)
        // We'll just leave it as is for down() or strictly revert if needed, but usually adding an enum value is safe to keep.
        // If we strictly want to revert:
        // DB::statement("DELETE FROM form_field_configurations WHERE field_type = 'file'");
        // DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN field_type ENUM('text', 'textarea', 'select', 'image', 'gallery', 'brand', 'model', 'number', 'date', 'richtext') NOT NULL");

        // For safety, we will just revert the definition but we won't delete data here to avoid accidental data loss.
        // Ideally, we should check if data exists before altering back.
        // For this dev environment, we'll try to revert the definition.
        DB::statement("ALTER TABLE form_field_configurations MODIFY COLUMN field_type ENUM('text', 'textarea', 'select', 'image', 'gallery', 'brand', 'model', 'number', 'date', 'richtext') NOT NULL");
    }
};
