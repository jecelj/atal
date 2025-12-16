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
        // 1. Ensure Sync as Taxonomy is ON (from previous step, but safe to repeat)
        // 2. IMPORTANT: Set is_multilingual = TRUE so it gets included in the 'translations' payload
        $config = FormFieldConfiguration::where('entity_type', 'used_yacht')
            ->where('field_key', 'tax_price')
            ->first();

        if ($config) {
            $config->sync_as_taxonomy = true;
            $config->is_multilingual = true;
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
