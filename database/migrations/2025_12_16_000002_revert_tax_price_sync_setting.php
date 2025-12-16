<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\FormFieldConfiguration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Revert "Tax price" to be Sync As Taxonomy = TRUE
        $config = FormFieldConfiguration::where('entity_type', 'used_yacht')
            ->where('field_key', 'tax_price')
            ->first();

        if ($config) {
            $config->sync_as_taxonomy = true; // Turn it back ON
            $config->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ...
    }
};
